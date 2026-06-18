<?php
namespace App\Services;

/** Минимальный генератор .docx (одна страница: заголовок + таблица «поле — значение»). */
class DocxWriter
{
    public static function visaCard(array $row, array $fields): string
    {
        $title = trim('Ходатайство № ' . ($row['out_no'] ?: $row['id']) . ' — ' . $row['surname_lat'] . ' ' . $row['names_lat']);
        $trs = '';
        foreach ($fields as $key => $label) {
            $val = (string) ($row[$key] ?? '');
            $trs .= '<w:tr>'
                . '<w:tc><w:tcPr><w:tcW w:w="3200" w:type="dxa"/></w:tcPr><w:p><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' . self::esc($label) . '</w:t></w:r></w:p></w:tc>'
                . '<w:tc><w:tcPr><w:tcW w:w="6800" w:type="dxa"/></w:tcPr><w:p><w:r><w:t xml:space="preserve">' . self::esc($val) . '</w:t></w:r></w:p></w:tc>'
                . '</w:tr>';
        }
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t xml:space="preserve">' . self::esc($title) . '</w:t></w:r></w:p>'
            . '<w:p/>'
            . '<w:tbl><w:tblPr><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="000000"/><w:left w:val="single" w:sz="4" w:color="000000"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="000000"/><w:right w:val="single" w:sz="4" w:color="000000"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="000000"/><w:insideV w:val="single" w:sz="4" w:color="000000"/>'
            . '</w:tblBorders></w:tblPr>' . $trs . '</w:tbl>'
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="850" w:bottom="1134" w:left="1134"/></w:sectPr>'
            . '</w:body></w:document>';

        $tmp = tempnam(sys_get_temp_dir(), 'dx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    /**
     * ОПИСЬ по стране (как create_opis_document/with_signature из garant):
     * заголовок = СТРАНА (жирный, по центру, Times New Roman 14), затем строки
     * «N. Фамилия Имя, ДД.ММ.ГГ, пол». $withSignature добавляет блок про Шереметьево и подпись.
     * @param array<int,array{fio:string,birth:string,gender:string}> $people
     */
    public static function opis(string $country, array $people, bool $withSignature = false, string $signer = 'К.О. Тринченко', string $signerPosition = 'Директор Департамента международного сотрудничества'): string
    {
        $body = self::para(mb_strtoupper($country, 'UTF-8'), ['bold' => true, 'center' => true]);
        $i = 0;
        foreach ($people as $p) {
            $i++;
            $line = $i . '. ' . trim($p['fio']);
            $line .= ', ' . $p['birth'];
            $line .= ', ' . $p['gender'];
            $body .= self::para($line);
        }
        if ($withSignature) {
            $body .= self::para('');
            $body .= self::para('Указанные иностранные граждане будут въезжать в Россию через аэропорт Шереметьево.', ['indent' => 709]);
            $body .= self::para('');
            $body .= self::para('');
            // Подпись одним абзацем: должность (неразрывные пробелы) + обычный пробел + Ф.И.О.
            // (инициалы неразрывны с фамилией) + мягкий перенос (Shift+Enter в Word) в конце — без рваных переносов.
            $text = self::nbsp($signerPosition) . ' ' . self::nbsp($signer);
            $runs = '<w:r><w:rPr>' . self::rpr() . '</w:rPr>'
                . '<w:t xml:space="preserve">' . self::esc($text) . '</w:t><w:br/></w:r>';
            $body .= '<w:p><w:pPr><w:spacing w:after="0" w:line="276" w:lineRule="auto"/></w:pPr>' . $runs . '</w:p>';
        }
        $sect = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="567" w:bottom="1134" w:left="1134"/></w:sectPr>';
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $body . $sect . '</w:body></w:document>';
        return self::package($document);
    }

    /** Абзац с шрифтом Times New Roman 14, интервал 1,15, отступ после 0. */
    private static function para(string $text, array $opt = []): string
    {
        $pPr = '<w:spacing w:after="0" w:line="276" w:lineRule="auto"/>';
        if (!empty($opt['center'])) { $pPr .= '<w:jc w:val="center"/>'; }
        if (!empty($opt['indent'])) { $pPr .= '<w:ind w:firstLine="' . (int) $opt['indent'] . '"/>'; }
        $rPr = self::rpr(!empty($opt['bold']));
        $run = $text === '' ? '' : '<w:r><w:rPr>' . $rPr . '</w:rPr><w:t xml:space="preserve">' . self::esc($text) . '</w:t></w:r>';
        return '<w:p><w:pPr>' . $pPr . '</w:pPr>' . $run . '</w:p>';
    }

    private static function rpr(bool $bold = false): string
    {
        return ($bold ? '<w:b/>' : '') . '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="28"/><w:szCs w:val="28"/>';
    }

    /** Упаковать document.xml в минимальный .docx. */
    private static function package(string $document): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Заменить обычные пробелы на неразрывные (U+00A0) — чтобы фраза не рвалась переносом. */
    public static function nbsp(string $s): string
    {
        return str_replace(' ', "\u{00A0}", trim($s));
    }
}
