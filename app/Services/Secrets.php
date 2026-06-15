<?php
namespace App\Services;

/**
 * Защищённое хранение секретов (API-ключи, пароли SMTP) в таблице settings.
 *
 * Значения шифруются AES-256-GCM перед записью в БД; мастер-ключ лежит в
 * storage/secret.key (вне веб-корня public/, генерируется автоматически при
 * первом обращении и НЕ должен попадать в пакеты переноса/резервные копии БД).
 * Утечка файла БД без мастер-ключа не раскрывает секреты.
 *
 * Старые значения, сохранённые открытым текстом, прозрачно перешифровываются
 * при первом чтении.
 */
class Secrets
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private static ?string $master = null;

    private static function masterKey(): string
    {
        if (self::$master !== null) { return self::$master; }
        $path = dirname(__DIR__, 2) . '/storage/secret.key';
        if (!is_file($path)) {
            $key = random_bytes(32);
            if (file_put_contents($path, $key, LOCK_EX) !== 32) {
                throw new \RuntimeException('Не удалось создать storage/secret.key');
            }
        }
        $key = (string) file_get_contents($path);
        if (strlen($key) !== 32) {
            throw new \RuntimeException('storage/secret.key повреждён (ожидается 32 байта)');
        }
        return self::$master = $key;
    }

    /** Зашифровать и сохранить секрет в settings. Пустая строка очищает значение. */
    public static function set(string $settingKey, string $plain): void
    {
        if ($plain === '') { Settings::set($settingKey, ''); return; }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, self::masterKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) { throw new \RuntimeException('Ошибка шифрования секрета'); }
        Settings::set($settingKey, self::PREFIX . base64_encode($iv . $tag . $ct));
    }

    /** Прочитать и расшифровать секрет. Открытые legacy-значения перешифровываются на месте. */
    public static function get(string $settingKey): string
    {
        $val = (string) (Settings::get($settingKey) ?? '');
        if ($val === '') { return ''; }
        if (strncmp($val, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            // Legacy: значение лежало открытым текстом — зашифровать при первом чтении.
            try { self::set($settingKey, $val); } catch (\Throwable $e) { /* читать всё равно можно */ }
            return $val;
        }
        $raw = base64_decode(substr($val, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) { return ''; }
        $plain = openssl_decrypt(
            substr($raw, 28), self::CIPHER, self::masterKey(),
            OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)
        );
        return $plain === false ? '' : $plain;
    }

    /** Задан ли секрет (без расшифровки). */
    public static function isSet(string $settingKey): bool
    {
        return (string) (Settings::get($settingKey) ?? '') !== '';
    }
}
