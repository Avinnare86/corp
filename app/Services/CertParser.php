<?php
namespace App\Services;

/**
 * Чтение реквизитов сертификата X.509 (.cer/.crt/.pem, DER или PEM) — владелец, серийный
 * номер, срок действия. Работает и для ГОСТ-сертификатов: основной разбор — собственный
 * минимальный ASN.1 (не требует поддержки алгоритма), openssl лишь уточняет данные.
 */
class CertParser
{
    /** @return array{serial:string,owner:string,from:string,to:string}|null */
    public static function parse(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') { return null; }
        $der = self::toDer($raw);
        if ($der === null) { return null; }

        $res = self::parseDer($der); // serial / from / to / owner (ASN.1)

        // Уточняем через openssl, если он сумел прочитать сертификат.
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        $info = @openssl_x509_parse($pem);
        if (is_array($info)) {
            $cn = $info['subject']['CN'] ?? ($info['subject']['commonName'] ?? null);
            if (is_array($cn)) { $cn = end($cn); }
            if ($cn) { $res['owner'] = (string) $cn; }
            if (!empty($info['serialNumberHex'])) { $res['serial'] = strtoupper(ltrim((string) $info['serialNumberHex'], '0')) ?: '0'; }
            elseif (isset($info['serialNumber'])) { $res['serial'] = (string) $info['serialNumber']; }
            if (isset($info['validFrom_time_t'])) { $res['from'] = date('Y-m-d', (int) $info['validFrom_time_t']); }
            if (isset($info['validTo_time_t']))   { $res['to']   = date('Y-m-d', (int) $info['validTo_time_t']); }
        }
        if (($res['serial'] ?? '') === '' && ($res['to'] ?? '') === '' && ($res['owner'] ?? '') === '') { return null; }
        return $res;
    }

    /** Привести содержимое файла к DER. */
    private static function toDer(string $raw): ?string
    {
        if (strpos($raw, '-----BEGIN') !== false) {
            if (preg_match('/-----BEGIN [^-]+-----(.+?)-----END/s', $raw, $m)) {
                $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
                return $der !== false ? $der : null;
            }
            return null;
        }
        if (strlen($raw) > 40 && ord($raw[0]) === 0x30) { return $raw; } // уже DER
        // голый base64 без рамок
        $compact = preg_replace('/\s+/', '', $raw);
        if ($compact !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact)) {
            $maybe = base64_decode($compact, true);
            if ($maybe !== false && strlen($maybe) > 40 && ord($maybe[0]) === 0x30) { return $maybe; }
        }
        return null;
    }

    /** Минимальный ASN.1-разбор TBSCertificate: serial, validity, subject CN. */
    private static function parseDer(string $der): array
    {
        $res = ['serial' => '', 'owner' => '', 'from' => '', 'to' => ''];
        $cert = self::tlv($der, 0);
        if (!$cert || $cert['tag'] !== 0x30) { return $res; }
        $tbs = self::tlv($der, $cert['vstart']);
        if (!$tbs || $tbs['tag'] !== 0x30) { return $res; }

        $q = $tbs['vstart'];
        $el = self::tlv($der, $q);
        if ($el && $el['tag'] === 0xA0) { $q = $el['end']; $el = self::tlv($der, $q); } // [0] version
        if ($el && $el['tag'] === 0x02) {                                              // serialNumber
            $res['serial'] = strtoupper(bin2hex(substr($der, $el['vstart'], $el['len'])));
            $res['serial'] = ltrim($res['serial'], '0') ?: '0';
            $q = $el['end']; $el = self::tlv($der, $q);
        }
        if ($el && $el['tag'] === 0x30) { $q = $el['end']; $el = self::tlv($der, $q); } // signature alg
        if ($el && $el['tag'] === 0x30) { $q = $el['end']; $el = self::tlv($der, $q); } // issuer
        if ($el && $el['tag'] === 0x30) {                                              // validity
            $vp = $el['vstart'];
            $nb = self::tlv($der, $vp); if ($nb) { $res['from'] = self::asnTime($der, $nb); $vp = $nb['end']; }
            $na = self::tlv($der, $vp); if ($na) { $res['to']   = self::asnTime($der, $na); }
            $q = $el['end']; $el = self::tlv($der, $q);
        }
        if ($el && $el['tag'] === 0x30) {                                              // subject → CN
            $res['owner'] = self::findCn($der, $el['vstart'], $el['vstart'] + $el['len']);
        }
        return $res;
    }

    private static function tlv(string $s, int $pos): ?array
    {
        $n = strlen($s);
        if ($pos + 1 >= $n) { return null; }
        $tag = ord($s[$pos]); $i = $pos + 1;
        $len = ord($s[$i++]);
        if ($len & 0x80) {
            $cnt = $len & 0x7F; $len = 0;
            for ($k = 0; $k < $cnt; $k++) { if ($i >= $n) { return null; } $len = ($len << 8) | ord($s[$i++]); }
        }
        return ['tag' => $tag, 'len' => $len, 'vstart' => $i, 'end' => $i + $len];
    }

    private static function asnTime(string $s, array $el): string
    {
        $v = substr($s, $el['vstart'], $el['len']);
        if ($el['tag'] === 0x17 && strlen($v) >= 6) { // UTCTime YYMMDD…
            $yy = (int) substr($v, 0, 2); $year = $yy >= 50 ? 1900 + $yy : 2000 + $yy;
            return sprintf('%04d-%s-%s', $year, substr($v, 2, 2), substr($v, 4, 2));
        }
        if ($el['tag'] === 0x18 && strlen($v) >= 8) { // GeneralizedTime YYYYMMDD…
            return substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2);
        }
        return '';
    }

    /** Найти commonName (OID 2.5.4.3) в subject и вернуть его строковое значение. */
    private static function findCn(string $s, int $start, int $end): string
    {
        $needle = "\x06\x03\x55\x04\x03"; // OID 06 03 55 04 03 = commonName
        $p = strpos($s, $needle, $start);
        while ($p !== false && $p < $end) {
            $str = self::tlv($s, $p + 5); // строковое значение сразу после OID
            if ($str && in_array($str['tag'], [0x0C, 0x13, 0x14, 0x1E, 0x16], true)) {
                $val = substr($s, $str['vstart'], $str['len']);
                if ($str['tag'] === 0x1E) { $val = mb_convert_encoding($val, 'UTF-8', 'UTF-16BE'); } // BMPString
                $val = trim($val);
                if ($val !== '') { return $val; }
            }
            $p = strpos($s, $needle, $p + 5);
        }
        return '';
    }
}
