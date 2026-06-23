<?php
namespace App\Services;

/**
 * Генератор .docx с поддержкой заголовков, абзацев, маркированных/нумерованных
 * списков, таблиц «поле—значение» и ВСТРОЕННЫХ PNG-скриншотов.
 *
 * Используется для пакета документации (инструкции по ролям + тех.документация).
 * Зависит только от расширения zip (gd не требуется — размеры PNG читаются из заголовка).
 *
 * Пример:
 *   $d = new DocxDoc('Инструкция: Табельщик');
 *   $d->heading('Вход в систему', 1)->para('Откройте портал ...');
 *   $d->image('C:\\shots\\login.png', 'Экран входа');
 *   $d->save('E:\\uchet\\udalit\\docs\\timekeeper.docx');
 */
class DocxDoc
{
    /** @var string[] фрагменты XML тела документа */
    private array $body = [];
    /** @var array<int,array{name:string,data:string,w:int,h:int}> картинки по rId-индексу */
    private array $images = [];
    private int $imgSeq = 0;

    private const EMU_PER_PX = 9525;        // 96 DPI
    private const MAX_IMG_PX = 640;         // ширина контента ~A4 при заданных полях

    public function __construct(string $title = '')
    {
        if ($title !== '') {
            $this->heading($title, 0);
        }
    }

    /** Заголовок. level 0 — титул (по центру, 32pt), 1 — раздел (18pt), 2 — подраздел (15pt), 3 — (13pt). */
    public function heading(string $text, int $level = 1): self
    {
        $sz = [0 => 36, 1 => 30, 2 => 26, 3 => 24][$level] ?? 24;
        $before = $level === 0 ? 0 : 240;
        $pPr = '<w:spacing w:before="' . $before . '" w:after="120"/>'
            . '<w:keepNext/>'
            . ($level === 0 ? '<w:jc w:val="center"/>' : '');
        $rPr = '<w:b/><w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>'
            . ($level <= 1 ? '<w:color w:val="1f3a5f"/>' : '');
        $this->body[] = '<w:p><w:pPr>' . $pPr . '</w:pPr>'
            . '<w:r><w:rPr>' . $rPr . '</w:rPr><w:t xml:space="preserve">' . self::esc($text) . '</w:t></w:r></w:p>';
        return $this;
    }

    /** Обычный абзац. Поддерживает **жирный** внутри текста. opt: muted (серый), center. */
    public function para(string $text, array $opt = []): self
    {
        $pPr = '<w:spacing w:after="120" w:line="276" w:lineRule="auto"/>'
            . (!empty($opt['center']) ? '<w:jc w:val="center"/>' : '');
        $this->body[] = '<w:p><w:pPr>' . $pPr . '</w:pPr>' . $this->runs($text, $opt) . '</w:p>';
        return $this;
    }

    /** Пункт маркированного списка («•»). */
    public function bullet(string $text, int $indentLevel = 0): self
    {
        $ind = 360 + $indentLevel * 360;
        $pPr = '<w:spacing w:after="60" w:line="276" w:lineRule="auto"/><w:ind w:left="' . $ind . '" w:hanging="280"/>';
        $marker = '<w:r><w:rPr>' . self::baseRpr() . '</w:rPr><w:t xml:space="preserve">•  </w:t></w:r>';
        $this->body[] = '<w:p><w:pPr>' . $pPr . '</w:pPr>' . $marker . $this->runs($text) . '</w:p>';
        return $this;
    }

    /** Пункт нумерованного списка («N. ») — простая нумерация без numbering.xml. */
    public function numbered(string $text, int $n, int $indentLevel = 0): self
    {
        $ind = 360 + $indentLevel * 360;
        $pPr = '<w:spacing w:after="60" w:line="276" w:lineRule="auto"/><w:ind w:left="' . $ind . '" w:hanging="320"/>';
        $marker = '<w:r><w:rPr>' . self::baseRpr(true) . '</w:rPr><w:t xml:space="preserve">' . $n . '.  </w:t></w:r>';
        $this->body[] = '<w:p><w:pPr>' . $pPr . '</w:pPr>' . $marker . $this->runs($text) . '</w:p>';
        return $this;
    }

    /** Таблица «поле — значение» (двухколоночная, с рамками). */
    public function kvTable(array $rows): self
    {
        $trs = '';
        foreach ($rows as $k => $v) {
            $trs .= '<w:tr>'
                . '<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/><w:shd w:val="clear" w:fill="eef2f7"/></w:tcPr><w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:b/>' . self::baseRpr() . '</w:rPr><w:t xml:space="preserve">' . self::esc((string) $k) . '</w:t></w:r></w:p></w:tc>'
                . '<w:tc><w:tcPr><w:tcW w:w="6600" w:type="dxa"/></w:tcPr><w:p><w:pPr><w:spacing w:after="0"/></w:pPr>' . $this->runs((string) $v) . '</w:p></w:tc>'
                . '</w:tr>';
        }
        $this->body[] = '<w:tbl><w:tblPr><w:tblW w:w="9600" w:type="dxa"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="b8c4d4"/><w:left w:val="single" w:sz="4" w:color="b8c4d4"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="b8c4d4"/><w:right w:val="single" w:sz="4" w:color="b8c4d4"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="b8c4d4"/><w:insideV w:val="single" w:sz="4" w:color="b8c4d4"/>'
            . '</w:tblBorders></w:tblPr>' . $trs . '</w:tbl><w:p><w:pPr><w:spacing w:after="60"/></w:pPr></w:p>';
        return $this;
    }

    /** Встроить PNG-скриншот (масштабируется по ширине контента) + опциональная подпись. */
    public function image(string $pngPath, string $caption = ''): self
    {
        if (!is_file($pngPath)) {
            $this->para('[нет скриншота: ' . basename($pngPath) . ']', ['muted' => true]);
            return $this;
        }
        $data = file_get_contents($pngPath);
        [$w, $h] = self::pngSize($data);
        if ($w <= 0 || $h <= 0) { $w = self::MAX_IMG_PX; $h = (int) round($w * 0.6); }
        if ($w > self::MAX_IMG_PX) { $h = (int) round($h * self::MAX_IMG_PX / $w); $w = self::MAX_IMG_PX; }
        $this->imgSeq++;
        $rId = 'imgRel' . $this->imgSeq;
        $name = 'image' . $this->imgSeq . '.png';
        $this->images[] = ['rid' => $rId, 'name' => $name, 'data' => $data];
        $cx = $w * self::EMU_PER_PX;
        $cy = $h * self::EMU_PER_PX;
        $docPr = $this->imgSeq;
        $drawing = '<w:p><w:pPr><w:spacing w:before="80" w:after="40"/><w:jc w:val="center"/></w:pPr>'
            . '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="' . $docPr . '" name="Picture ' . $docPr . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="' . $docPr . '" name="' . $name . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
        $this->body[] = $drawing;
        if ($caption !== '') {
            $this->body[] = '<w:p><w:pPr><w:spacing w:after="160"/><w:jc w:val="center"/></w:pPr>'
                . '<w:r><w:rPr><w:i/><w:color w:val="6b7280"/>' . self::baseRpr() . '</w:rPr>'
                . '<w:t xml:space="preserve">' . self::esc($caption) . '</w:t></w:r></w:p>';
        }
        return $this;
    }

    public function pageBreak(): self
    {
        $this->body[] = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        return $this;
    }

    /** Собрать набор runs из текста с поддержкой **жирного**. */
    private function runs(string $text, array $opt = []): string
    {
        $muted = !empty($opt['muted']);
        $parts = preg_split('/(\*\*.+?\*\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) { $parts = [$text]; }
        $out = '';
        foreach ($parts as $p) {
            $bold = false;
            if (preg_match('/^\*\*(.+?)\*\*$/u', $p, $m)) { $bold = true; $p = $m[1]; }
            $rPr = ($bold ? '<w:b/>' : '') . ($muted ? '<w:color w:val="6b7280"/>' : '') . self::baseRpr();
            $out .= '<w:r><w:rPr>' . $rPr . '</w:rPr><w:t xml:space="preserve">' . self::esc($p) . '</w:t></w:r>';
        }
        return $out;
    }

    private static function baseRpr(bool $bold = false): string
    {
        return ($bold ? '<w:b/>' : '') . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/>';
    }

    /** Размер PNG из заголовка IHDR (без gd). */
    private static function pngSize(string $data): array
    {
        if (strlen($data) < 24 || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") { return [0, 0]; }
        if (substr($data, 12, 4) !== 'IHDR') { return [0, 0]; }
        $w = unpack('N', substr($data, 16, 4))[1] ?? 0;
        $h = unpack('N', substr($data, 20, 4))[1] ?? 0;
        return [(int) $w, (int) $h];
    }

    public function save(string $path): void
    {
        file_put_contents($path, $this->build());
    }

    public function build(): string
    {
        $hasImg = $this->images !== [];
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:body>' . implode('', $this->body)
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="850" w:bottom="1134" w:left="1134" w:header="708" w:footer="708"/></w:sectPr>'
            . '</w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . ($hasImg ? '<Default Extension="png" ContentType="image/png"/>' : '')
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->images as $img) {
            $rels .= '<Relationship Id="' . $img['rid'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $img['name'] . '"/>';
        }
        $rels .= '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'dx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');
        $zip->addFromString('word/document.xml', $document);
        if ($hasImg) {
            $zip->addFromString('word/_rels/document.xml.rels', $rels);
            foreach ($this->images as $img) {
                $zip->addFromString('word/media/' . $img['name'], $img['data']);
            }
        }
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
