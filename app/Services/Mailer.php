<?php
namespace App\Services;

use App\Core\Database;

/**
 * Минимальный SMTP-клиент для email-уведомлений (без внешних библиотек).
 * Настройки в settings: smtp_enabled, smtp_host, smtp_port, smtp_secure (ssl|tls|none),
 * smtp_user, smtp_pass, smtp_from. Отправка best-effort: ошибки не ломают работу.
 */
class Mailer
{
    public static function enabled(): bool
    {
        return Settings::get('smtp_enabled') === '1' && Settings::get('smtp_host');
    }

    /** Отправить письмо пользователю системы (если у него заполнен email). */
    public static function toUser(int $userId, string $subject, string $body): void
    {
        if (!self::enabled()) { return; }
        $email = (string) Database::scalar('SELECT email FROM users WHERE id = ?', [$userId]);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return; }
        try {
            self::send($email, $subject, $body);
        } catch (\Throwable $e) {
            Audit::log('MAIL_FAIL', 'Ошибка отправки email', $e->getMessage());
        }
    }

    public static function send(string $to, string $subject, string $body): void
    {
        $host = (string) Settings::get('smtp_host');
        $port = (int) (Settings::get('smtp_port') ?: 465);
        $secure = (string) (Settings::get('smtp_secure') ?: 'ssl');
        $user = (string) Settings::get('smtp_user');
        $pass = Secrets::get('smtp_pass');
        $from = (string) (Settings::get('smtp_from') ?: $user);

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = stream_socket_client($remote, $errno, $errstr, 8);
        if (!$fp) { throw new \RuntimeException("SMTP connect: $errstr"); }
        stream_set_timeout($fp, 8);
        $read = function () use ($fp) {
            $out = '';
            while (($line = fgets($fp, 512)) !== false) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') { break; }
            }
            return $out;
        };
        $cmd = function (string $c, array $okCodes) use ($fp, $read) {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!in_array((int) substr($r, 0, 3), $okCodes, true)) { throw new \RuntimeException("SMTP: $c → $r"); }
            return $r;
        };

        $read(); // приветствие
        $cmd('EHLO uchet.local', [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO uchet.local', [250]);
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode($pass), [235]);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $headers = 'From: =?UTF-8?B?' . base64_encode('Учёт работы специалистов') . "?= <{$from}>\r\n"
            . "To: <{$to}>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        fwrite($fp, $headers . "\r\n" . chunk_split(base64_encode($body)) . "\r\n.\r\n");
        $r = $read();
        if ((int) substr($r, 0, 3) !== 250) { throw new \RuntimeException("SMTP DATA: $r"); }
        $cmd('QUIT', [221]);
        fclose($fp);
    }
}
