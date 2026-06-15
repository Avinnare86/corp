<?php
namespace App\Services;

/**
 * Клиент OpenRouter (chat/completions). Ключ и модель — в настройках
 * (openrouter_key, openrouter_model — открытое поле), промпт виз — visa_prompt.
 */
class OpenRouter
{
    public static function configured(): bool
    {
        return Secrets::isSet('openrouter_key') && Settings::get('openrouter_model');
    }

    /** Один chat-запрос; возвращает текст ответа модели. Бросает исключение при ошибке. */
    public static function chat(string $prompt, int $maxTokens = 8192): string
    {
        $key = Secrets::get('openrouter_key');
        $model = (string) Settings::get('openrouter_model');
        if ($key === '' || $model === '') {
            throw new \RuntimeException('OpenRouter не настроен: укажите ключ и модель в настройках.');
        }
        $body = json_encode([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ], JSON_UNESCAPED_UNICODE);

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$key}\r\nHTTP-Referer: http://uchet.local\r\nX-Title: Uchet\r\n",
            'content' => $body,
            'timeout' => 120,
            'ignore_errors' => true,
        ]]);
        $resp = file_get_contents('https://openrouter.ai/api/v1/chat/completions', false, $ctx);
        if ($resp === false) { throw new \RuntimeException('OpenRouter: сеть недоступна.'); }
        $data = json_decode($resp, true);
        if (isset($data['error'])) {
            throw new \RuntimeException('OpenRouter: ' . ($data['error']['message'] ?? json_encode($data['error'])));
        }
        $text = $data['choices'][0]['message']['content'] ?? null;
        if ($text === null) { throw new \RuntimeException('OpenRouter: пустой ответ — ' . mb_substr($resp, 0, 300)); }
        return (string) $text;
    }

    /**
     * Пакетная подстановка адресов (как VBA-модуль): записи {id, country, address}
     * → ответ ROW_X: адрес → карта [id => адрес].
     */
    public static function fillAddresses(array $records): array
    {
        $base = (string) Settings::get('visa_prompt');
        $prompt = $base . "\n\n"
            . "ВАЖНО: Обработай следующие записи. Для каждой записи выведи ТОЛЬКО итоговый адрес в формате:\n"
            . "ROW_X: [итоговый адрес]\n\n"
            . "где X — номер записи (1, 2, 3...). НЕ добавляй пояснений.\n"
            . "=====================================\n\n";
        foreach (array_values($records) as $i => $r) {
            $n = $i + 1;
            $prompt .= "ЗАПИСЬ #{$n}:\n";
            if ($r['country'] !== '') { $prompt .= "Государство проживания: {$r['country']}\n"; }
            if ($r['address'] !== '') { $prompt .= "Адрес места работы: {$r['address']}\n"; }
            $prompt .= "\n";
        }
        $prompt .= "=====================================\nОбработай все " . count($records) . " записи и выведи результаты в формате ROW_X: [адрес]";

        $answer = self::chat($prompt);

        $out = [];
        $list = array_values($records);
        foreach (preg_split('/\r?\n/', $answer) as $line) {
            if (preg_match('/ROW[_\s#]*(\d+)\s*[:\-]\s*(.+)$/ui', trim($line), $m)) {
                $idx = (int) $m[1] - 1;
                if (isset($list[$idx])) { $out[$list[$idx]['id']] = trim($m[2]); }
            }
        }
        return $out;
    }
}
