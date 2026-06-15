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

            foreach ($trs[0] as $tr) {
                preg_match_all('/<w:tc(?:>| ).*?<\/w:tc>/s', $tr, $tcs);
                $cells = [];
                foreach ($tcs[0] as $tc) {
                    // закладки в ячейке
                    preg_match_all('/<w:bookmarkStart[^>]*w:name="([^"]+)"/', $tc, $bms);
                    $cells[] = ['text' => self::cellText($tc), 'bookmarks' => $bms[1]];
                }
                // 1) по закладкам
                foreach ($cells as $c) {
                    foreach ($c['bookmarks'] as $bm) {
                        if (isset(self::BOOKMARKS[$bm]) && $c['text'] !== '') {
                            $row[self::BOOKMARKS[$bm]] = $c['text'];
                        }
                    }
                }
                // 2) fallback по подписям (берём следующую непустую ячейку)
                $vals = array_values(array_filter(array_map(fn($c) => $c['text'], $cells), fn($v) => $v !== ''));
                foreach ($vals as $i => $v) {
                    foreach (self::LABELS as $label => $field) {
                        if ($row[$field] === '' && mb_stripos($v, $label) === 0 && isset($vals[$i + 1])) {
                            $row[$field] = $vals[$i + 1];
                        }
                    }
                }
            }

            // отбраковка пустышек
            if ($row['surname_ru'] === '' && $row['passport_no'] === '' && $row['out_no'] === '') { continue; }
            $row['source_file'] = $origName;
            $row['table_no'] = $ti + 1;
            $out[] = $row;
        }
        return $out;
    }

    private static function cellText(string $tc): string
    {
        // точная граница тега: <w:t> или <w:t ...>, но НЕ <w:tcPr>/<w:tbl…>
        preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $tc, $ts);
        $t = html_entity_decode(implode(' ', $ts[1]), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    }
}
