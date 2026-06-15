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
