<?php
namespace App\Services;

/**
 * Общие хелперы формирования визовых документов (ОПИСЬ / ОПИСЬ-СП / гарантийное письмо,
 * списки по странам). Подписант (ФИО + должность) задаётся при формировании описи;
 * дефолты берутся из настроек (visa_signer_name / visa_signer_position).
 */
class VisaDocs
{
    /** Должность подписанта по умолчанию (как в шаблоне gp.docx и оригинальной программе). */
    public const DEFAULT_POSITION = 'Директор Департамента международного сотрудничества';
    /** ФИО подписанта по умолчанию (литерал в шаблоне gp.docx). */
    public const DEFAULT_SIGNER = 'К.О. Тринченко';

    /** Колонки списка по странам (как в исходном «Разделителе EXCEL по странам»). */
    public const LIST_HEADERS = [
        'Исходящий №', 'Фамилия (рус)', 'Имена (рус)', 'Фамилия (лат)', 'Имена (лат)',
        'Гражданство (подданство)', 'Государство проживания', 'Дата рождения', 'Место рождения', 'Пол',
        'Номер документа, удостоверяющего личность', 'Дата выдачи (день, месяц, год)', 'Действителен до (день, месяц, год)',
        'Адрес места работы, тел, факс', 'Пункты (города) посещения в России', 'Место получения визы',
        'Источник файл', 'Номер таблицы в файле',
    ];

    /** Путь к шаблону гарантийного письма. */
    public static function gpTemplatePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/templates/gp.docx';
    }

    /** Дефолтное ФИО подписанта (из настроек, иначе литерал шаблона). */
    public static function defaultSignerName(): string
    {
        $s = trim((string) Settings::get('visa_signer_name', ''));
        return $s !== '' ? $s : self::DEFAULT_SIGNER;
    }

    /** Дефолтная должность подписанта (из настроек, иначе как в шаблоне). */
    public static function defaultSignerPosition(): string
    {
        $s = trim((string) Settings::get('visa_signer_position', ''));
        return $s !== '' ? $s : self::DEFAULT_POSITION;
    }

    /** Строка Excel-списка по визовой строке (порядок = LIST_HEADERS). */
    public static function listRow(array $r): array
    {
        return [
            $r['out_no'], $r['surname_ru'], $r['names_ru'], $r['surname_lat'], $r['names_lat'],
            $r['citizenship'], $r['residence'], $r['birth_date'], $r['birth_place'], $r['sex'],
            $r['passport_no'], $r['issue_date'], $r['expiry_date'],
            ($r['ai_address'] ?? '') ?: ($r['work_address'] ?? ''), $r['visit_places'], $r['visa_place'],
            $r['source_file'], $r['table_no'],
        ];
    }

    /** Имя листа Excel из названия страны (≤31 символ, без запрещённых символов). */
    public static function sheetName(string $country): string
    {
        $n = preg_replace('#[\\\\/?*\[\]:]#u', '', $country);
        $n = trim(mb_substr($n, 0, 31, 'UTF-8'));
        return $n !== '' ? $n : 'Без страны';
    }

    /** Плейсхолдеры гарантийного письма для страны: {K} {hc} {a} {kl}. */
    public static function gpPlaceholders(string $country, int $count): array
    {
        // {kl} = «N листе/листах», N — оценка числа страниц ОПИСИ (как count_pages_in_document).
        $pages = CountryDecl::pagesForOpis($count);
        return [
            'K'  => CountryDecl::genitive($country),
            'hc' => CountryDecl::numberToWordsGenitive($count),
            'a'  => CountryDecl::peopleEnding($count),
            'kl' => CountryDecl::sheetsPhrase($pages),
        ];
    }

    /**
     * Замены фиксированного текста шаблона ГП: ФИО подписанта и его должность.
     * Подставляются только при отличии от значений по умолчанию (иначе текст шаблона сохраняется).
     */
    public static function gpLiterals(string $signerName, string $signerPosition): array
    {
        $lit = [];
        $signerName = trim($signerName);
        $signerPosition = trim($signerPosition);
        if ($signerName !== '' && $signerName !== self::DEFAULT_SIGNER) {
            $lit[self::DEFAULT_SIGNER] = $signerName;
        }
        if ($signerPosition !== '' && $signerPosition !== self::DEFAULT_POSITION) {
            $lit[self::DEFAULT_POSITION] = $signerPosition;
        }
        return $lit;
    }

    /** Люди для ОПИСИ из строк (как create_opis_document: B/C/H/J → ФИО, дата, пол). */
    public static function opisPeople(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $surname = CountryDecl::formatFullName((string) $r['surname_ru']);
            $name    = CountryDecl::formatFullName((string) $r['names_ru']);
            $out[] = [
                'fio'    => trim($surname . ' ' . $name),
                'birth'  => CountryDecl::formatDate((string) $r['birth_date']),
                'gender' => CountryDecl::formatGender((string) $r['sex']),
            ];
        }
        return $out;
    }

    /** Базовое имя файла страны (как country_name: пробел/«/»→_, кавычки удаляются). */
    public static function opisFileBase(string $country): string
    {
        $n = str_replace([' ', '/'], '_', $country);
        $n = str_replace(["'", '"'], '', $n);
        return $n !== '' ? $n : 'страна';
    }

    /**
     * Собрать ZIP с тремя документами по стране (ОПИСЬ / ОПИСЬ-СП / ГП) + список Excel.
     * @param array<int,array> $rows визовые строки одной страны
     * @return string бинарное содержимое .zip
     */
    public static function bundleForCountry(string $country, array $rows, string $signerName, string $signerPosition): string
    {
        $n = count($rows);
        $base = self::opisFileBase($country);
        $people = self::opisPeople($rows);
        $tpl = self::gpTemplatePath();

        $tmp = tempnam(sys_get_temp_dir(), 'gpc');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString($base . '_ОПИСЬ_' . $n . '.docx',
            DocxWriter::opis($country, $people, false));
        $zip->addFromString($base . '_ОПИСЬСП_' . $n . '.docx',
            DocxWriter::opis($country, $people, true, $signerName, $signerPosition));
        if (is_file($tpl)) {
            $zip->addFromString($base . '_ГП_' . $n . '.docx',
                DocxTemplate::fill($tpl, self::gpPlaceholders($country, $n), self::gpLiterals($signerName, $signerPosition)));
        }
        $zip->addFromString('Список_' . $base . '.xlsx',
            Xlsx::build([['name' => self::sheetName($country), 'headers' => self::LIST_HEADERS, 'rows' => array_map([self::class, 'listRow'], $rows)]]));
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }
}
