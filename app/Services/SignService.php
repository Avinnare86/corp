<?php

namespace App\Services;

/**
 * Клиент централизованного сервиса электронной подписи (КриптоПро DSS, sc.ined.ru).
 *
 * Реализует:
 *  - выпуск/перевыпуск сертификата УКЭП пользователя (create_user_cert);
 *  - подпись документа по паролю (sign_file → открепленная подпись CAdES в base64).
 *
 * Контракт повторяет грантовый контур (EcpController): аутентификация по паролю
 * (base64), без API-токена; TLS не проверяется (внутренний контур). HTTP идёт через
 * stream-context — расширение curl не требуется (в портативном PHP его нет).
 *
 * Идентификатор пользователя в DSS префиксуется ({@see dssUserId}), чтобы не
 * пересекаться с другими контурами (гранты), использующими тот же сервис.
 */
class SignService
{
    /** Базовый URL DSS-сервиса (без хвостового слэша). */
    public static function baseUrl(): string
    {
        return rtrim((string) Settings::get('sign_dss_url', 'https://sc.ined.ru/api'), '/');
    }

    /** Включена ли реальная подпись через сервис (URL задан и не отключено в настройках). */
    public static function enabled(): bool
    {
        return self::baseUrl() !== '' && (string) Settings::get('sign_dss_enabled', '1') !== '0';
    }

    /** Идентификатор пользователя в DSS (с префиксом контура). */
    public static function dssUserId(int $userId): string
    {
        return (string) Settings::get('sign_dss_user_prefix', 'uchet-') . $userId;
    }

    /**
     * Универсальная подпись документа — единая точка для всех типов документов
     * (табель, график сменности, служебка, СЭД, график отпусков).
     *
     * УКЭП при включённом сервисе → реальная подпись содержимого через DSS по паролю,
     * открепленная .sig сохраняется в document_signatures. ПЭП/УНЭП (или УКЭП без
     * сервиса) → подтверждение пароля учётной записи + HMAC-отпечаток (прежнее поведение).
     * В обоих случаях запись добавляется в журнал document_signatures.
     *
     * @return array{ok:bool, sign_type:string, serial:string, fingerprint:string, sign_hash:string, signed_at:string, error?:string}
     */
    public static function signDocument(string $entityType, int $entityId, int $userId, string $signType, string $password, string $payload): array
    {
        $d = self::authAndSign($entityType, $entityId, $userId, $signType, $password, $payload);
        if (!$d['ok']) { return ['ok' => false, 'error' => $d['error']]; }
        self::recordSignature($entityType, $entityId, $userId, $d);
        return ['ok' => true, 'sign_type' => $d['sign_type'], 'serial' => $d['serial'],
                'fingerprint' => $d['fingerprint'], 'sign_hash' => $d['sign_hash'], 'signed_at' => $d['signed_at']];
    }

    /**
     * Аутентифицировать подписанта и сформировать дескриптор подписи БЕЗ записи в журнал.
     * Нужно тем потокам, где id документа появляется только после INSERT (например, график
     * сменности): сначала authAndSign(), затем INSERT документа, затем recordSignature() с id.
     *
     * УКЭП при включённом сервисе → реальная .sig по паролю DSS; ПЭП/УНЭП (или УКЭП без
     * сервиса) → пароль учётной записи + HMAC-штамп (прежнее поведение).
     *
     * @return array{ok:bool, sign_type:string, serial:string, fingerprint:string, sign_hash:string, payload_sha:string, signed_at:string, sig_b64:?string, error?:string}
     */
    public static function authAndSign(string $entityType, int $entityId, int $userId, string $signType, string $password, string $payload): array
    {
        $signType = strtoupper($signType);
        $now = date('Y-m-d H:i:s');
        $shaPayload = hash('sha256', $payload);

        // ---- УКЭП через сервис: реальная криптоподпись по паролю DSS ----
        if ($signType === 'UKEP' && self::enabled()) {
            $res = self::signBytes($userId, $password, $payload, $entityType . '-' . $entityId . '.txt');
            if (!$res['ok']) { return ['ok' => false, 'error' => $res['error']]; }
            $cert = \App\Core\Database::one(
                "SELECT serial, fingerprint FROM user_certificates WHERE user_id=? AND sign_type='UKEP' AND source='dss' AND valid_to>=? ORDER BY id DESC LIMIT 1",
                [$userId, date('Y-m-d')]);
            return ['ok' => true, 'sign_type' => 'UKEP', 'serial' => (string) ($cert['serial'] ?? ''),
                    'fingerprint' => (string) ($cert['fingerprint'] ?? ''), 'sign_hash' => $shaPayload,
                    'payload_sha' => $shaPayload, 'signed_at' => $now, 'sig_b64' => $res['sign']];
        }

        // ---- ПЭП / УНЭП (или УКЭП без сервиса): пароль учётной записи + HMAC-штамп ----
        $hash = \App\Core\Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if ($password === '' || !password_verify($password, (string) $hash)) {
            return ['ok' => false, 'error' => 'Неверный пароль — подпись не выполнена.'];
        }
        $cert = self::ensureCert($userId, $signType);
        if (!$cert) {
            return ['ok' => false, 'error' => 'Сертификат для выбранного вида подписи не зарегистрирован. Обратитесь к администратору или выпустите УКЭП в разделе «Моя ЭП».'];
        }
        $secret = (string) Settings::get('sign_secret');
        if ($secret === '') { $secret = bin2hex(random_bytes(16)); Settings::set('sign_secret', $secret); }
        $signHash = hash_hmac('sha256', $payload . '|' . $userId . '|' . $cert['serial'] . '|' . $now, $secret);
        return ['ok' => true, 'sign_type' => $signType, 'serial' => (string) $cert['serial'],
                'fingerprint' => (string) ($cert['fingerprint'] ?? ''), 'sign_hash' => $signHash,
                'payload_sha' => $shaPayload, 'signed_at' => $now, 'sig_b64' => null];
    }

    /** Записать подпись в журнал document_signatures по дескриптору из {@see authAndSign}. */
    public static function recordSignature(string $entityType, int $entityId, int $userId, array $desc): void
    {
        // Идемпотентность: не дублировать запись при повторной отправке формы
        // (та же сущность + тот же подписант + то же содержимое). Разное содержимое
        // (новая ревизия) и разные подписанты (многоступенчатый маршрут) — допускаются.
        $dup = \App\Core\Database::scalar(
            'SELECT 1 FROM document_signatures WHERE entity_type=? AND entity_id=? AND signer_id=? AND payload_sha256=? LIMIT 1',
            [$entityType, $entityId, $userId, (string) ($desc['payload_sha'] ?? '')]);
        if ($dup) { return; }
        \App\Core\Database::insert(
            'INSERT INTO document_signatures (entity_type, entity_id, signer_id, sign_type, serial, fingerprint, sig_b64, payload_sha256, signed_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$entityType, $entityId, $userId, $desc['sign_type'], $desc['serial'], $desc['fingerprint'] ?? '',
             $desc['sig_b64'] ?? null, $desc['payload_sha'] ?? '', $desc['signed_at']]);
    }

    /** Получить активный сертификат вида $type; ПЭП — автo-выпуск при первом подписании. */
    public static function ensureCert(int $userId, string $type): ?array
    {
        $type = strtoupper($type);
        $cert = \App\Core\Database::one('SELECT * FROM user_certificates WHERE user_id=? AND sign_type=? AND valid_to>=? ORDER BY id DESC LIMIT 1',
            [$userId, $type, date('Y-m-d')]);
        if ($cert) { return $cert; }
        if ($type === 'PEP') {
            $owner = (string) \App\Core\Database::scalar('SELECT full_name FROM users WHERE id=?', [$userId]);
            $serial = 'PEP-' . strtoupper(bin2hex(random_bytes(6)));
            try {
                \App\Core\Database::insert(
                    'INSERT INTO user_certificates (user_id, sign_type, serial, owner_name, source, issued_at, valid_to) VALUES (?,?,?,?,?,?,?)',
                    [$userId, 'PEP', $serial, $owner, 'manual', date('Y-m-d'), date('Y-m-d', strtotime('+5 years'))]);
            } catch (\Throwable $e) {
                return null; // не удалось автоматически выпустить ПЭП — подпись не выполнится, ошибка показывается пользователю
            }
            return \App\Core\Database::one('SELECT * FROM user_certificates WHERE serial=?', [$serial]);
        }
        return null;
    }

    /** Реквизиты последней ДЕЙСТВУЮЩЕЙ (не погашенной) подписи документа — для отображения штампа. */
    public static function lastSignature(string $entityType, int $entityId): ?array
    {
        return \App\Core\Database::one(
            'SELECT * FROM document_signatures WHERE entity_type=? AND entity_id=? AND voided_at IS NULL ORDER BY id DESC LIMIT 1',
            [$entityType, $entityId]);
    }

    /**
     * Погасить (аннулировать) подписи документа при откате администратором: записи в журнале
     * не удаляются, а помечаются voided_at/voided_by — для целостного аудита. Возвращает число
     * погашенных подписей. Вызывать при снятии подписи/утверждения (revert).
     */
    public static function revoke(string $entityType, int $entityId, int $byUserId, string $reason = ''): int
    {
        $n = (int) \App\Core\Database::scalar(
            'SELECT COUNT(*) FROM document_signatures WHERE entity_type=? AND entity_id=? AND voided_at IS NULL',
            [$entityType, $entityId]);
        if ($n > 0) {
            \App\Core\Database::run(
                'UPDATE document_signatures SET voided_at=?, voided_by=? WHERE entity_type=? AND entity_id=? AND voided_at IS NULL',
                [date('Y-m-d H:i:s'), $byUserId, $entityType, $entityId]);
            \App\Services\Audit::log('signature.revoke', 'Аннулирование подписи: ' . $entityType . ' #' . $entityId,
                ['entity' => $entityType, 'id' => $entityId, 'count' => $n, 'reason' => $reason]);
        }
        return $n;
    }

    /**
     * Погасить только ПОСЛЕДНЮЮ действующую подпись документа (для пошагового отката
     * многоступенчатого маршрута: снять подпись текущего этапа, оставив предыдущие).
     */
    public static function revokeLast(string $entityType, int $entityId, int $byUserId): bool
    {
        $row = \App\Core\Database::one(
            'SELECT id FROM document_signatures WHERE entity_type=? AND entity_id=? AND voided_at IS NULL ORDER BY id DESC LIMIT 1',
            [$entityType, $entityId]);
        if (!$row) { return false; }
        \App\Core\Database::run('UPDATE document_signatures SET voided_at=?, voided_by=? WHERE id=?',
            [date('Y-m-d H:i:s'), $byUserId, (int) $row['id']]);
        \App\Services\Audit::log('signature.revoke', 'Аннулирование подписи (этап): ' . $entityType . ' #' . $entityId,
            ['entity' => $entityType, 'id' => $entityId]);
        return true;
    }

    /**
     * Выпустить (или перевыпустить при override) сертификат УКЭП пользователя.
     * Пароль одновременно регистрирует/обновляет учётную запись пользователя в DSS.
     *
     * @return array{ok:bool, certificate?:array{serial:string,common_name:string,fingerprint:string,not_before:?string,not_after:?string}, error?:string, status?:int}
     */
    public static function issueCert(int $userId, string $password, string $fio, string $email = ''): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'Сервис подписи не настроен (sign_dss_url).'];
        }
        $parts = preg_split('/\s+/u', trim($fio)) ?: [];
        $payload = [
            'user_id'  => self::dssUserId($userId),
            'password' => base64_encode($password),
            'fio'      => $fio,
            'fam'      => $parts[0] ?? '',
            'name'     => $parts[1] ?? '',
            'email'    => $email,
            'override' => true,
        ];
        $res = self::httpJson(self::baseUrl() . '/create_user_cert', $payload);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'status' => $res['status'] ?? 0];
        }
        $cert = $res['json']['certificate'] ?? [];
        return [
            'ok' => true,
            'certificate' => [
                'serial'      => (string) ($cert['serial'] ?? ''),
                'common_name' => (string) ($cert['common_name'] ?? $fio),
                'fingerprint' => (string) ($cert['fingerprint'] ?? ''),
                'not_before'  => $cert['not_before'] ?? null,
                'not_after'   => $cert['not_after'] ?? null,
            ],
        ];
    }

    /**
     * Подписать байты документа. Возвращает открепленную подпись (.sig) в base64.
     *
     * @return array{ok:bool, sign?:string, error?:string, status?:int}
     */
    public static function signBytes(int $userId, string $password, string $bytes, string $filename = 'document.pdf'): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'Сервис подписи не настроен (sign_dss_url).'];
        }
        $fields = [
            'user_id'  => self::dssUserId($userId),
            'password' => base64_encode($password),
        ];
        $res = self::httpMultipart(self::baseUrl() . '/sign_file', $fields, 'file', $filename, $bytes);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'status' => $res['status'] ?? 0];
        }
        $sign = (string) ($res['json']['sign'] ?? '');
        if ($sign === '') {
            return ['ok' => false, 'error' => 'Сервис не вернул подпись (поле sign пустое).'];
        }
        return ['ok' => true, 'sign' => $sign];
    }

    // ===== транспорт (stream-context, без curl) =====

    private static function httpJson(string $url, array $payload): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        return self::send($url, $ctx);
    }

    private static function httpMultipart(string $url, array $fields, string $fileField, string $filename, string $bytes): array
    {
        $boundary = '----uchet' . bin2hex(random_bytes(12));
        $eol = "\r\n";
        $body = '';
        foreach ($fields as $k => $v) {
            $body .= "--{$boundary}{$eol}";
            $body .= "Content-Disposition: form-data; name=\"{$k}\"{$eol}{$eol}";
            $body .= $v . $eol;
        }
        $body .= "--{$boundary}{$eol}";
        $body .= "Content-Disposition: form-data; name=\"{$fileField}\"; filename=\"{$filename}\"{$eol}";
        $body .= "Content-Type: application/octet-stream{$eol}{$eol}";
        $body .= $bytes . $eol;
        $body .= "--{$boundary}--{$eol}";
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: multipart/form-data; boundary={$boundary}\r\nAccept: application/json\r\n",
                'content'       => $body,
                'timeout'       => 60,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        return self::send($url, $ctx);
    }

    private static function send(string $url, $ctx): array
    {
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            $err = error_get_last()['message'] ?? 'нет соединения';
            return ['ok' => false, 'error' => 'Сервис подписи недоступен: ' . $err, 'status' => 0];
        }
        $status = self::statusFromHeaders($http_response_header ?? []);
        $json = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($json) ? ($json['error'] ?? $json['message'] ?? '') : '';
            return ['ok' => false, 'status' => $status, 'json' => is_array($json) ? $json : [],
                    'error' => 'Сервис подписи вернул ошибку ' . $status . ($msg ? ': ' . $msg : '')];
        }
        return ['ok' => true, 'json' => is_array($json) ? $json : [], 'status' => $status];
    }

    private static function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
