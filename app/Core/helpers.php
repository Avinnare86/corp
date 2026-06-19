<?php
/**
 * Глобальные помощники, доступные в шаблонах.
 */

if (!function_exists('e')) {
    /** HTML-экранирование. */
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    /** Форматирование денег: 1 234,50 ₽ */
    function money($value): string
    {
        return number_format((float) $value, 2, ',', ' ') . ' ₽';
    }
}

if (!function_exists('csrf_field')) {
    /** Скрытое поле CSRF для форм. */
    function csrf_field(): string
    {
        $token = \App\Core\Auth::csrf();
        return '<input type="hidden" name="_csrf" value="' . e($token) . '">';
    }
}

if (!function_exists('ep_stamp')) {
    /**
     * Штамп электронной подписи (единый для служебок и документов СЭД).
     * Inline-стили — чтобы одинаково рисовался и с общим шаблоном, и в печатных формах без него.
     * Если даты подписи нет — возвращает ''.
     */
    function ep_stamp(string $who, ?string $owner, ?string $signedAt, ?string $type = 'PEP', ?string $hash = ''): string
    {
        if (!$signedAt) { return ''; }
        $typeLabel = ['PEP' => 'ПЭП (простая ЭП)', 'UNEP' => 'УНЭП', 'UKEP' => 'УКЭП'][$type ?? ''] ?? ($type ?: 'ПЭП');
        $box = 'border:2px solid #1a56b8;border-radius:10px;padding:8px 12px;color:#1a56b8;'
             . 'font-family:Arial,sans-serif;font-size:9pt;line-height:1.5;margin:8px 0;max-width:430px';
        $h = '<div style="' . $box . '"><b style="font-size:10pt;letter-spacing:.03em">ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ</b><br>'
           . 'Роль: ' . e($who) . '<br>'
           . 'Вид подписи: ' . e($typeLabel) . '<br>'
           . 'Владелец: ' . e($owner ?? '') . '<br>'
           . 'Подписано: ' . e(substr((string) $signedAt, 0, 16));
        if ($hash) { $h .= '<br>Отпечаток: ' . e(substr((string) $hash, 0, 24)); }
        return $h . '</div>';
    }
}

if (!function_exists('arrival_label')) {
    /** Линия прибытия для отображения: «ЛП.code/ДЛП.text» (напр. ПП/У ШОС (…)). '' если пусто. */
    function arrival_label(?string $code, ?string $detail): string
    {
        $code = trim((string) $code);
        $detail = trim((string) $detail);
        if ($code === '' && $detail === '') { return ''; }
        return $code !== '' ? ($code . ($detail !== '' ? '/' . $detail : '')) : $detail;
    }
}

if (!function_exists('old')) {
    /** Значение из flash-данных предыдущего ввода. */
    function old(string $key, $default = ''): string
    {
        return e($_SESSION['_old'][$key] ?? $default);
    }
}

if (!function_exists('flash')) {
    /** Записать или прочитать flash-сообщение. */
    function flash(?string $message = null, string $type = 'success')
    {
        if ($message !== null) {
            $_SESSION['_flash'] = ['message' => $message, 'type' => $type];
            return null;
        }
        $f = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return $f;
    }
}
