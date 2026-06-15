<?php
namespace App\Services;

/**
 * Склонение стран и форматирование данных для ОПИСИ и гарантийного письма —
 * перенесено один-в-один из программы garant (generate_documents.py + countries.py).
 */
class CountryDecl
{
    /** Список исключений ФИО (пишутся строчными). */
    private const EXCEPTIONS = [
        'оглы','огли','угли','углы','оглу','углу','улы','уулу',
        'гызы','кызы','кизи','кыз','кыс','кысы','кыцы','кузы','фон','унтер',
    ];

    private static ?array $map = null;
    private static function map(): array
    {
        if (self::$map === null) { self::$map = require __DIR__ . '/country_genitive.php'; }
        return self::$map;
    }

    /** Страна в родительном падеже (для «гражданами {K}»). Словарь ОКСМ + эвристика. */
    public static function genitive(string $country): string
    {
        $key = mb_strtoupper(trim($country), 'UTF-8');
        $m = self::map();
        if (isset($m[$key])) { return $m[$key]; }
        return self::declineFallback($key);
    }

    /** Фолбэк для стран не из словаря — как format_country_name(case='genitive'). */
    private static function declineFallback(string $upper): string
    {
        $words = preg_split('/\s+/u', $upper);
        $fmt = [];
        foreach ($words as $w) {
            if ($w === '') { continue; }
            if (mb_strpos($w, '-') !== false) {
                $fmt[] = implode('-', array_map(fn($p) => $p === '' ? '' : mb_strtoupper(mb_substr($p,0,1,'UTF-8'),'UTF-8') . mb_strtolower(mb_substr($p,1,null,'UTF-8'),'UTF-8'), explode('-', $w)));
            } else {
                $fmt[] = mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8') . mb_strtolower(mb_substr($w,1,null,'UTF-8'),'UTF-8');
            }
        }
        $result = implode(' ', $fmt);
        // аббревиатура (всё заглавными, ≤5) — не склоняется
        if (mb_strtoupper($result,'UTF-8') === $result && mb_strlen($result,'UTF-8') <= 5) { return $result; }
        $tail2 = mb_substr($result, -2, 2, 'UTF-8'); $tail1 = mb_substr($result, -1, 1, 'UTF-8');
        if ($tail2 === 'ия') { return mb_substr($result,0,-2,'UTF-8') . 'ии'; }
        if ($tail1 === 'я')  { return mb_substr($result,0,-1,'UTF-8') . 'и'; }
        if ($tail1 === 'а')  { return mb_substr($result,0,-1,'UTF-8') . 'ы'; }
        if ($tail1 === 'й')  { return mb_substr($result,0,-1,'UTF-8') . 'я'; }
        if ($tail1 === 'ь')  { return mb_substr($result,0,-1,'UTF-8') . 'я'; }
        // согласная: добавляем «а», если это не похоже на аббревиатуру
        $rest = mb_substr($result,1,null,'UTF-8');
        $hasUpperInside = preg_match('/[А-ЯЁA-Z]/u', $rest) === 1;
        if (!($hasUpperInside || mb_strlen($result,'UTF-8') <= 3)) { return $result . 'а'; }
        return $result;
    }

    // ---- число прописью (родительный падеж), 0–999 ----
    private const NUM_UNITS  = ['','одного','двух','трех','четырех','пяти','шести','семи','восьми','девяти'];
    private const NUM_TEENS  = ['десяти','одиннадцати','двенадцати','тринадцати','четырнадцати','пятнадцати','шестнадцати','семнадцати','восемнадцати','девятнадцати'];
    private const NUM_TENS   = ['','','двадцати','тридцати','сорока','пятидесяти','шестидесяти','семидесяти','восьмидесяти','девяноста'];
    private const NUM_HUNDS  = ['','ста','двухсот','трехсот','четырехсот','пятисот','шестисот','семисот','восьмисот','девятисот'];

    public static function numberToWordsGenitive(int $n): string
    {
        if ($n === 0) { return 'нуля'; }
        if ($n < 0 || $n >= 1000) { return (string) $n; }
        $parts = [];
        $h = intdiv($n, 100); if ($h > 0) { $parts[] = self::NUM_HUNDS[$h]; }
        $rem = $n % 100;
        if ($rem >= 10 && $rem < 20) { $parts[] = self::NUM_TEENS[$rem - 10]; }
        else { $t = intdiv($rem,10); $o = $rem % 10; if ($t>0) $parts[]=self::NUM_TENS[$t]; if ($o>0) $parts[]=self::NUM_UNITS[$o]; }
        return implode(' ', $parts);
    }

    /** Окончание «человек{a}»: «а» если число оканчивается на 1 (кроме 11), иначе пусто. */
    public static function peopleEnding(int $n): string
    {
        if ($n >= 10 && $n % 100 === 11) { return ''; }
        return ($n % 10 === 1) ? 'а' : '';
    }

    /** Слово «лист»: 1 → листе, ≥2 → листах. Возвращает «N листе/листах». */
    public static function sheetsPhrase(int $pages): string
    {
        $pages = max(1, $pages);
        return $pages . ' ' . ($pages === 1 ? 'листе' : 'листах');
    }

    /** Число страниц описи — как count_pages_in_document: ~40 абзацев на страницу (заголовок + люди). */
    public static function pagesForOpis(int $peopleCount): int
    {
        $paragraphs = $peopleCount + 1; // заголовок + по абзацу на человека
        return max(1, intdiv($paragraphs + 39, 40));
    }

    // ---- форматирование ФИО / пола / даты (как в generate_documents.py) ----
    private static function formatNamePart(string $part): string
    {
        if ($part === '') { return $part; }
        $low = mb_strtolower($part, 'UTF-8');
        if (in_array($low, self::EXCEPTIONS, true)) { return $low; }
        foreach (['-','–','—'] as $sep) {
            if (mb_strpos($part, $sep) !== false) {
                return implode($sep, array_map([self::class,'formatNamePart'], explode($sep, $part)));
            }
        }
        return mb_strtoupper(mb_substr($part,0,1,'UTF-8'),'UTF-8') . mb_strtolower(mb_substr($part,1,null,'UTF-8'),'UTF-8');
    }

    public static function isOnlyDashes(string $text): bool
    {
        $text = trim($text);
        if ($text === '') { return false; }
        return preg_match('/^[\-\x{2013}\x{2014}\x{2015}\x{2012}\x{2212}_ ]+$/u', $text) === 1;
    }

    public static function formatFullName(string $name): string
    {
        if ($name === '' || self::isOnlyDashes($name)) { return ''; }
        $parts = preg_split('/\s+/u', trim($name));
        return implode(' ', array_map([self::class,'formatNamePart'], $parts));
    }

    public static function formatGender(string $g): string
    {
        $u = mb_strtoupper(trim($g), 'UTF-8');
        if ($u === 'M' || $u === 'М') { return 'муж'; }
        if ($u === 'Ж' || $u === 'F') { return 'жен'; }
        return $g;
    }

    /** Дата → ДД.ММ.ГГ (двузначный год), как format_date. */
    public static function formatDate(string $v): string
    {
        $v = trim($v);
        if ($v === '') { return ''; }
        foreach (['d.m.Y','d.m.y','Y-m-d','d/m/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat('!' . $fmt, $v);
            if ($dt !== false) {
                $err = \DateTime::getLastErrors();
                if (!$err || ($err['warning_count'] === 0 && $err['error_count'] === 0)) { return $dt->format('d.m.y'); }
            }
        }
        return $v;
    }

    /** Подписант ГП: те же инициалы, фамилия с заглавной (О.Д. МОЛОВЦЕВА → О.Д. Моловцева). */
    public static function signerTitleCase(string $signer): string
    {
        $signer = trim($signer);
        if ($signer === '') { return ''; }
        $tokens = preg_split('/\s+/u', $signer);
        $out = [];
        foreach ($tokens as $t) {
            if (mb_strpos($t, '.') !== false) { $out[] = $t; continue; } // инициалы — как есть
            $out[] = self::formatNamePart($t);
        }
        return implode(' ', $out);
    }
}
