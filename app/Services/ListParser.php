<?php
namespace App\Services;

class ListParser
{
    // Рег. номер досье: КОД-НОМЕР/ГОД, напр. VNM-12538/26. Без /u — номера ASCII,
    // флаг /u падает на невалидном UTF-8 при склейке XML.
    private const PATTERN = '/([A-Z]{2,4}-\d{2,}\/\d{2,4})/';

    /**
     * Извлечь уникальные рег. номера из загруженного файла.
     * Поддержка: docx, xlsx (zip+xml), csv/txt (plain), xls (byte-regex, best-effort).
     */
    public static function extract(string $path, string $origName): array
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $text = '';

        if (in_array($ext, ['docx', 'xlsx'], true) && class_exists('ZipArchive')) {
            $text = self::fromZipXml($path);
        } elseif ($ext === 'xls') {
            $text = self::fromBinary($path);
        } else {
            // csv, txt и всё прочее — читаем как текст.
            $text = (string) file_get_contents($path);
        }

        return self::matchAll($text);
    }

    /**
     * Извлечь рег. номера С УЧЁТОМ секций «линии прибытия» (только .docx).
     * Заголовок «… детализированным Планом приема …: ЗНАЧЕНИЕ» → ЛП=ПП + ДЛП=ЗНАЧЕНИЕ;
     * заголовок «… Планом приема …» без «детализированного» (или без значения) → только ЛП=ПП (ДЛП нет).
     * Возвращает ['reg'=>..., 'line'=>'ПП'|'', 'detail'=>...] (dedup по reg, первый выигрывает).
     * Не-docx / нет заголовков → line='' и detail='' (обратная совместимость).
     */
    public static function extractStructured(string $path, string $origName): array
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext === 'docx' && class_exists('ZipArchive')) {
            return self::docxRows($path);
        }
        // Прочие форматы — без секций: номера без линии.
        $out = [];
        foreach (self::extract($path, $origName) as $reg) { $out[] = ['reg' => $reg, 'line' => '', 'detail' => '']; }
        return $out;
    }

    /** Построчный разбор word/document.xml: заголовки «Линия прибытия» → ЛП(ПП)/ДЛП, рег. номера → секция. */
    private static function docxRows(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) { return []; }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) { return []; }
        // Границы абзацев/строк таблицы → переводы строк, затем снять теги.
        $xml = str_replace(['</w:p>', '</w:tr>'], ["\n", "\n"], $xml);
        $text = html_entity_decode(preg_replace('/<[^>]+>/', '', $xml), ENT_QUOTES, 'UTF-8');

        $curLine = '';    // ЛП (для загрузок «Плана приема» — ПП)
        $curDetail = '';  // ДЛП (только из «детализированного» заголовка)
        $seen = [];
        $out = [];
        foreach (preg_split('/\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            // Заголовок секции «Линия прибытия … [детализированным] Планом приема …».
            // Опознаём по ФРАЗЕ (двоеточие/значение необязательны) и ВСЕГДА сбрасываем линию,
            // чтобы ДЛП из прошлой секции не «прилипала» к новой (заголовок без значения → просто ПП).
            if (preg_match('/Лини[ия]\s*прибыт.*?План.*?прием/ui', $line)) {
                $curLine = 'ПП'; // линия прибытия для загрузок — всегда План приема
                // ДЛП — только из «детализированного» заголовка и только если есть значение после двоеточия.
                $curDetail = (preg_match('/детализир/ui', $line) && preg_match('/:\s*(.+)$/u', $line, $m))
                    ? preg_replace('/\s+/u', ' ', trim($m[1])) : '';
                continue;
            }
            // Рег. номера в строке.
            if (preg_match_all(self::PATTERN, strtoupper($line), $r)) {
                foreach ($r[1] as $reg) {
                    if (isset($seen[$reg])) { continue; }
                    $seen[$reg] = true;
                    $out[] = ['reg' => $reg, 'line' => $curLine, 'detail' => $curDetail];
                }
            }
        }
        return $out;
    }

    private static function matchAll(string $text): array
    {
        $text = strtoupper($text);
        preg_match_all(self::PATTERN, $text, $m);
        $list = array_values(array_unique($m[1] ?? []));
        return $list;
    }

    /** Прочитать все XML-части офисного файла и снять теги (склеивает разорванные run'ы). */
    private static function fromZipXml(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $buf = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (substr($name, -4) === '.xml') {
                $xml = $zip->getFromIndex($i);
                if ($xml !== false) {
                    // Снять теги, чтобы соседние фрагменты текста склеились.
                    $buf .= ' ' . preg_replace('/<[^>]+>/', '', $xml);
                }
            }
        }
        $zip->close();
        return html_entity_decode($buf, ENT_QUOTES, 'UTF-8');
    }

    /** Старый .xls (BIFF) — вытаскиваем читаемые байтовые строки. */
    private static function fromBinary(string $path): string
    {
        $bytes = (string) file_get_contents($path);
        // ASCII-представление достаёт латинские коды и цифры рег. номеров (1-байтовые строки BIFF).
        return preg_replace('/[^\x20-\x7E]/', ' ', $bytes);
    }
}
