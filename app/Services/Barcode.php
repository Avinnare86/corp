<?php
namespace App\Services;

/**
 * Линейный штрихкод Code39 в виде SVG (без зависимостей) — для регистрационных
 * карточек СЭД (потоковая обработка/поиск документа по штрихкоду).
 * Code39 кодирует 0-9 A-Z и символы - . $ / + % пробел. Кириллица не поддерживается,
 * поэтому кодируем числовой идентификатор документа (а рег.№ печатаем текстом рядом).
 */
class Barcode
{
    // символ => 9 модулей: пары (ширина,тип) где тип 1=полоса,0=пробел; n=1, w=2 (узкий/широкий)
    private const PATT = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
    ];

    /** SVG со штрихкодом Code39 для строки $data (приводится к верхнему регистру, недопустимые символы удаляются). */
    public static function code39svg(string $data, int $height = 46, float $narrow = 1.6): string
    {
        $data = strtoupper($data);
        $data = preg_replace('/[^0-9A-Z\-\. ]/', '', $data);
        if ($data === '') { $data = '0'; }
        $seq = '*' . $data . '*';
        $x = 0.0; $rects = '';
        for ($i = 0; $i < strlen($seq); $i++) {
            $patt = self::PATT[$seq[$i]] ?? self::PATT['*'];
            for ($j = 0; $j < 9; $j++) {
                $w = ($patt[$j] === 'w' ? 3 : 1) * $narrow;
                $isBar = ($j % 2 === 0);
                if ($isBar) { $rects .= '<rect x="' . round($x, 2) . '" y="0" width="' . round($w, 2) . '" height="' . $height . '"/>'; }
                $x += $w;
            }
            $x += $narrow; // межсимвольный узкий пробел
        }
        $w = round($x, 2);
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $height . '" viewBox="0 0 ' . $w . ' ' . $height . '" fill="#000">' . $rects . '</svg>';
    }
}
