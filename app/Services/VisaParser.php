<?php
namespace App\Services;

/**
 * Парсер визовых ходатайств из .docx: одна анкета = одна таблица.
 * Основной механизм — закладки Word (petitionNumber, lastNameRu, workAddress…),
 * fallback — поиск значения в ячейке после ячейки-подписи.
 */
class VisaParser
{
    /** Карта закладок docx → поле visa_rows. */
    private const BOOKMARKS = [
        'petitionNumber'         => 'out_no',
        'dateFrom'               => 'out_date',
        'lastNameRu'             => 'surname_ru',
        'firstAndMiddleNamesRu'  => 'names_ru',
        'lastNameEn'             => 'surname_lat',
        'firstNameEn'            => 'names_lat',
        'citizenship'            => 'citizenship',
        'country'                => 'residence',
        'dateOfBirth'            => 'birth_date',
        'visaPlaceOfBirth'       => 'birth_place',
        'sex'                    => 'sex',
        'passportNumber'         => 'passport_no',
        'passportDate'           => 'issue_date',
        'passportExpirationDate' => 'expiry_date',
        'workAddress'            => 'work_address',
        'placeOfVisaObtaining'   => 'visa_place',
    ];

    /** Подписи (fallback) → поле. Значение = следующая непустая ячейка строки. */
    private const LABELS = [
        'Исходящий'                 => 'out_no',
        'Фамилия (рус)'             => 'surname_ru',
        'Имена(рус)'                => 'names_ru',
        'Имена (рус)'               => 'names_ru',
        'Фамилия(лат)'              => 'surname_lat',
        'Имена (лат)'               => 'names_lat',
        'Гражданство'               => 'citizenship',
        'Государство проживания'    => 'residence',
        'Дата рождения'             => 'birth_date',
        'Место рождения'            => 'birth_place',
        'Пол'                       => 'sex',
        'Номер документа'           => 'passport_no',
        'Дата выдачи'               => 'issue_date',
        'Действителен до'           => 'expiry_date',
        'Пункты (города) посещения' => 'visit_places',
        'Место получения визы'      => 'visa_place',
    ];

    /** Разбор файла: массив анкет (поля visa_rows) + имя файла/номер таблицы. */
    public static function parse(string $path, string $origName): array
    {
        if (!class_exists('ZipArchive')) { return []; }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) { return []; }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) { return []; }

        $out = [];
        preg_match_all('/<w:tbl(?:>| ).*?<\/w:tbl>/s', $xml, $tbls);
        foreach ($tbls[0] as $ti => $tbl) {
            preg_match_all('/<w:tr(?:>| ).*?<\/w:tr>/s', $tbl, $trs);
            if (count($trs[0]) < 20) { continue; } // не анкета

            $row = array_fill_keys(array_values(self::BOOKMARKS), '');
            $row['visit_places'] = '';

            // 1) ПЕРВИЧНО — по закладкам Word: <w:bookmarkStart name="X"/> … <w:bookmarkEnd>.
            // Закладка обёрнута прямо вокруг значения, поэтому данные не сдвигаются и не путаются.
            foreach (self::BOOKMARKS as $bm => $field) {
                if (preg_match('/<w:bookmarkStart\b[^>]*\bw:name="' . preg_quote($bm, '/') . '"[^>]*\/>(.*?)<w:bookmarkEnd\b/s', $tbl, $m)) {
                    $v = self::runsText($m[1]);
                    if ($v !== '') { $row[$field] = $v; }
                }
            }

            // 2) FALLBACK — по подписям (без учёта пробелов/регистра): значение = следующая непустая
            // ячейка строки. Чинит подписи с «рваными» пробелами («Фамилия ( рус )», «Имен а( рус)»).
            $rowsVals = [];
            foreach ($trs[0] as $tr) {
                preg_match_all('/<w:tc(?:>| ).*?<\/w:tc>/s', $tr, $tcs);
                $vals = [];
                foreach ($tcs[0] as $tc) {
                    $t = self::cellText($tc);
                    if ($t !== '') { $vals[] = $t; }
                }
                $rowsVals[] = $vals;
                foreach ($vals as $i => $v) {
                    if (!isset($vals[$i + 1])) { continue; }
                    $vn = self::norm($v);
                    if ($vn === '') { continue; }
                    foreach (self::LABELS as $label => $field) {
                        if ($row[$field] === '' && mb_strpos($vn, self::norm($label)) === 0) {
                            $row[$field] = $vals[$i + 1];
                        }
                    }
                }
            }
            // out_date: «Исходящий № … от <дата>» — берём ячейку строго после «от».
            if ($row['out_date'] === '') {
                foreach ($rowsVals as $vals) {
                    foreach ($vals as $i => $v) {
                        if (self::norm($v) === 'от' && isset($vals[$i + 1])) { $row['out_date'] = $vals[$i + 1]; break 2; }
                    }
                }
            }
            // work_address: часто значение в СЛЕДУЮЩЕЙ строке отдельной ячейкой после подписи «Адрес места работы…».
            if ($row['work_address'] === '') {
                $addrLab = self::norm('Адрес места работы');
                foreach ($rowsVals as $ri => $vals) {
                    foreach ($vals as $ci => $v) {
                        if (mb_strpos(self::norm($v), $addrLab) !== 0) { continue; }
                        $cand = (isset($vals[$ci + 1]) && $vals[$ci + 1] !== '') ? $vals[$ci + 1] : ($rowsVals[$ri + 1][0] ?? '');
                        $isLabel = false;
                        foreach (self::LABELS as $lab => $f) { if ($cand !== '' && mb_strpos(self::norm($cand), self::norm($lab)) === 0) { $isLabel = true; break; } }
                        if ($cand !== '' && !$isLabel) { $row['work_address'] = $cand; }
                        break 2;
                    }
                }
            }

            // отбраковка пустышек
            if ($row['surname_ru'] === '' && $row['surname_lat'] === '' && $row['passport_no'] === '' && $row['out_no'] === '') { continue; }
            $row['source_file'] = $origName;
            $row['table_no'] = $ti + 1;
            $out[] = $row;
        }
        return $out;
    }

    /** Нормализация для сравнения подписей: без пробелов, в нижнем регистре. */
    private static function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $s) ?? '', 'UTF-8');
    }

    /** Склейка текста ранов <w:t> внутри спана закладки (без лишних пробелов). */
    private static function runsText(string $frag): string
    {
        preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $frag, $ts);
        $t = html_entity_decode(implode('', $ts[1]), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    private static function cellText(string $tc): string
    {
        // точная граница тега: <w:t> или <w:t ...>, но НЕ <w:tcPr>/<w:tbl…>
        preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $tc, $ts);
        $t = html_entity_decode(implode(' ', $ts[1]), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    }
}
