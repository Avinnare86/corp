<?php
namespace App\Services;

/**
 * Разбор рег. номеров анкет, введённых вручную: одиночные номера, перечисление через запятую/
 * с новой строки и диапазоны через тире.
 *
 * Формат номера: КОД-НОМЕР/ГОД (например RUS-0001/26). Диапазон: «RUS-0001/26 - RUS-0009/26»
 * (тире, ен-/эм-тире). У диапазона код и год должны совпадать; ширина нулей берётся максимальная
 * из концов. Возвращает уникальные номера (с сохранением порядка) и список нераспознанных токенов.
 */
class RegRangeParser
{
    private const MAX_RANGE = 5000; // защита от случайного огромного диапазона

    /** @return array{regs: string[], bad: string[]} */
    public static function parse(string $text): array
    {
        $single = '/^([A-ZА-ЯЁ0-9]{1,12})-(\d+)\/(\d{2,4})$/u';
        $range  = '/^([A-ZА-ЯЁ0-9]{1,12})-(\d+)\/(\d{2,4})\s*[-–—]\s*([A-ZА-ЯЁ0-9]{1,12})-(\d+)\/(\d{2,4})$/u';

        $regs = [];
        $bad = [];
        foreach (preg_split('/[,;\r\n]+/u', $text) ?: [] as $tok) {
            $tok = mb_strtoupper(trim($tok), 'UTF-8');
            if ($tok === '') { continue; }

            if (preg_match($range, $tok, $m)) {
                if ($m[1] !== $m[4] || $m[3] !== $m[6]) { $bad[] = $tok; continue; }
                $a = (int) $m[2];
                $b = (int) $m[5];
                if ($b < $a) { [$a, $b] = [$b, $a]; }
                if ($b - $a > self::MAX_RANGE) { $bad[] = $tok . ' (диапазон > ' . self::MAX_RANGE . ')'; continue; }
                $width = max(strlen($m[2]), strlen($m[5]));
                for ($i = $a; $i <= $b; $i++) {
                    $regs[] = $m[1] . '-' . str_pad((string) $i, $width, '0', STR_PAD_LEFT) . '/' . $m[3];
                }
            } elseif (preg_match($single, $tok)) {
                $regs[] = $tok;
            } else {
                $bad[] = $tok;
            }
        }
        return ['regs' => array_values(array_unique($regs)), 'bad' => $bad];
    }

    /** Код страны рег. номера (часть до первого «-»). */
    public static function countryCode(string $reg): string
    {
        return explode('-', $reg)[0] ?? '';
    }
}
