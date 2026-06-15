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
