<?php
namespace App\Services;

/**
 * Минимальный генератор .xlsx (без зависимостей, через ZipArchive).
 * Использование: Xlsx::download('file.xlsx', [ ['name'=>'Лист','headers'=>[...],'rows'=>[[...],...]] ]);
 */
class Xlsx
{
    public static function build(array $sheets): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        $sheetXmls = [];
        foreach ($sheets as $i => $s) {
            $sheetXmls[] = self::sheetXml($s['headers'] ?? [], $s['rows'] ?? []);
        }

        // [Content_Types].xml
        $overrides = '';
        foreach ($sheets as $i => $s) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides . '</Types>');

        // _rels/.rels
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        // xl/workbook.xml + rels
        $sheetsTags = ''; $rels = '';
        foreach ($sheets as $i => $s) {
            $n = $i + 1;
            $name = self::esc(mb_substr($s['name'] ?? ('Лист' . $n), 0, 31));
            $sheetsTags .= '<sheet name="' . $name . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsTags . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');

        foreach ($sheetXmls as $i => $xml) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
        }
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    public static function download(string $filename, array $sheets): void
    {
        $data = self::build($sheets);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }

    private static function sheetXml(array $headers, array $rows): string
    {
        $all = [];
        if ($headers) { $all[] = $headers; }
        foreach ($rows as $r) { $all[] = $r; }
        $body = '';
        foreach ($all as $ri => $row) {
            $cells = '';
            $ci = 0;
            foreach ($row as $val) {
                $ref = self::col($ci) . ($ri + 1);
                if (is_int($val) || is_float($val)) {
                    $cells .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
                } else {
                    $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $val) . '</t></is></c>';
                }
                $ci++;
            }
            $body .= '<row r="' . ($ri + 1) . '">' . $cells . '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . $body . '</sheetData></worksheet>';
    }

    private static function col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = (int) (($i - $m) / 26); }
        return $s;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
