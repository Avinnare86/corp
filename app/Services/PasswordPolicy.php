<?php
namespace App\Services;

class PasswordPolicy
{
    /** Минимальная длина пароля. */
    public const MIN_LEN = 8;

    /** Разрешённые спецсимволы (включая многобайтовый №). */
    public const SPECIAL = '!$%&#@()*^/?><.,_-+=~`"\':;№';

    /** Текст требований для подсказки в форме. */
    public static function hint(): string
    {
        return 'Не короче ' . self::MIN_LEN . ' символов; минимум одна заглавная буква, одна строчная, '
            . 'одна цифра и один спецсимвол (' . self::SPECIAL . ').';
    }

    /**
     * Проверить пароль на соответствие требованиям.
     * @return string|null текст ошибки или null, если пароль допустим.
     */
    public static function validate(string $pw): ?string
    {
        if (mb_strlen($pw) < self::MIN_LEN) {
            return 'Пароль должен быть не короче ' . self::MIN_LEN . ' символов.';
        }
        if (!preg_match('/\p{Lu}/u', $pw)) {
            return 'В пароле нужна хотя бы одна заглавная буква (A–Z, А–Я).';
        }
        if (!preg_match('/\p{Ll}/u', $pw)) {
            return 'В пароле нужна хотя бы одна строчная буква (a–z, а–я).';
        }
        if (!preg_match('/\d/', $pw)) {
            return 'В пароле нужна хотя бы одна цифра.';
        }
        $special = preg_split('//u', self::SPECIAL, -1, PREG_SPLIT_NO_EMPTY);
        $hasSpecial = false;
        foreach (preg_split('//u', $pw, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (in_array($ch, $special, true)) { $hasSpecial = true; break; }
        }
        if (!$hasSpecial) {
            return 'В пароле нужен хотя бы один спецсимвол (' . self::SPECIAL . ').';
        }
        return null;
    }
}
