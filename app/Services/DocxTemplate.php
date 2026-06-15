<?php
namespace App\Services;

/**
 * Подстановка данных в готовый .docx-шаблон БЕЗ изменения самого шаблона:
 * меняется только текст внутри узлов <w:t>, вся разметка/стили/таблицы
 * остаются байт-в-байт (требование МИД к бланку ходатайства).
 *
 * Поддерживает два вида замен:
 *  - плейсхолдеры в фигурных скобках {Фамилия (рус)} — ключи сопоставляются
 *    без учёта регистра и пробелов (Word дробит текст на прогоны произвольно);
 *  - точные текстовые фрагменты (даты «02.05.25», подписант «В.В. СУЩИК»),
 *    даже если они разбиты на несколько прогонов w:r.
 *
 * Подставленный текст наследует форматирование прогона, в котором начинался
 * заменяемый фрагмент.
 */
class DocxTemplate
{
    /**
     * Заполнить шаблон. $placeholders: ['Фамилия (рус)' => 'IVANOV', …] для {…};
     * $literals: ['02.05.25' => '01.06.26', …] — замена точного видимого текста
     * (все вхождения). Пустые значения пропускаются (текст шаблона сохраняется).
     * Возвращает бинарное содержимое нового .docx.
     */
    public static function fill(string $templatePath, array $placeholders, array $literals = []): string
    {
        if (!is_file($templatePath)) {
            throw new \RuntimeException('Шаблон не найден: ' . $templatePath);
        }
        $src = new \ZipArchive();
        if ($src->open($templatePath) !== true) {
            throw new \RuntimeException('Не удалось открыть шаблон .docx');
        }
        $xml = $src->getFromName('word/document.xml');
        $src->close();
        if ($xml === false) { throw new \RuntimeException('В шаблоне нет word/document.xml'); }

        $newXml = self::transform($xml, $placeholders, $literals);

        // Копия шаблона с заменённым document.xml — остальные части не трогаем.
        $tmp = tempnam(sys_get_temp_dir(), 'dtpl');
        copy($templatePath, $tmp);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    /**
     * Заполнить ОДИН шаблон НЕСКОЛЬКИМИ наборами данных и склеить в один .docx:
     * тело каждой копии идёт с новой страницы, единый sectPr/стили/медиа берутся из шаблона
     * (все страницы — один и тот же бланк, поэтому склейка на уровне XML корректна).
     * Это даёт верный многостраничный PDF при одиночной конвертации (без слияния в Word).
     *
     * @param array<int,array<string,string>> $rowsPlaceholders по одному набору {…} на страницу
     */
    public static function fillCombined(string $templatePath, array $rowsPlaceholders, array $literals = []): string
    {
        if (!is_file($templatePath)) { throw new \RuntimeException('Шаблон не найден: ' . $templatePath); }
        $rowsPlaceholders = array_values($rowsPlaceholders);
        if (count($rowsPlaceholders) <= 1) { return self::fill($templatePath, $rowsPlaceholders[0] ?? [], $literals); }

        $src = new \ZipArchive();
        if ($src->open($templatePath) !== true) { throw new \RuntimeException('Не удалось открыть шаблон .docx'); }
        $xml = $src->getFromName('word/document.xml');
        $src->close();
        if ($xml === false) { throw new \RuntimeException('В шаблоне нет word/document.xml'); }

        if (!preg_match('/<w:body\b[^>]*>(.*)<\/w:body>/s', $xml, $bm, PREG_OFFSET_CAPTURE)) {
            return self::fill($templatePath, $rowsPlaceholders[0], $literals);
        }
        $innerOffset = $bm[1][1];
        $innerLen    = strlen($bm[1][0]);

        // sectPr (параметры страницы бланка) — вынести один раз в конец общего тела.
        $sectPr = '';
        if (preg_match('/<w:sectPr\b.*<\/w:sectPr>\s*$/s', $bm[1][0], $sm) || preg_match('/<w:sectPr\b[^>]*\/>\s*$/s', $bm[1][0], $sm)) {
            $sectPr = $sm[0];
        }
        $pageBreak = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

        $pages = [];
        foreach ($rowsPlaceholders as $p => $ph) {
            $filled = self::transform($xml, $ph, $literals);
            if (!preg_match('/<w:body\b[^>]*>(.*)<\/w:body>/s', $filled, $fm)) { continue; }
            $inner = preg_replace(['/<w:sectPr\b.*<\/w:sectPr>\s*$/s', '/<w:sectPr\b[^>]*\/>\s*$/s'], '', $fm[1]);
            $pages[] = self::shiftIds($inner, $p); // уникализировать id закладок/рисунков на каждой странице
        }
        if (!$pages) { return self::fill($templatePath, $rowsPlaceholders[0], $literals); }

        $combined = implode($pageBreak, $pages) . $sectPr;
        $newXml = substr($xml, 0, $innerOffset) . $combined . substr($xml, $innerOffset + $innerLen);

        $tmp = tempnam(sys_get_temp_dir(), 'dtpl');
        copy($templatePath, $tmp);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    /** Сместить числовые id закладок и рисунков, чтобы не было коллизий между склеенными страницами. */
    private static function shiftIds(string $inner, int $page): string
    {
        if ($page === 0) { return $inner; }
        $base = ($page + 1) * 100000;
        $inner = preg_replace_callback('/(<w:bookmark(?:Start|End)\b[^>]*\bw:id=")(\d+)(")/', fn($m) => $m[1] . ($base + (int) $m[2]) . $m[3], $inner);
        $inner = preg_replace_callback('/(<wp:docPr\b[^>]*\bid=")(\d+)(")/', fn($m) => $m[1] . ($base + (int) $m[2]) . $m[3], $inner);
        return $inner;
    }

    /** Ключ сопоставления: без регистра и пробельных символов. */
    private static function norm(string $s): string
    {
        return (string) preg_replace('/\s+/u', '', mb_strtolower($s));
    }

    private static function transform(string $xml, array $placeholders, array $literals): string
    {
        // Все текстовые узлы по порядку + их сквозной текст (Word дробит фразы на прогоны).
        if (!preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $xml, $m, PREG_OFFSET_CAPTURE)) {
            return $xml;
        }
        $nodes = [];
        $concat = '';
        foreach ($m[0] as $i => [$full, $off]) {
            $text = html_entity_decode($m[1][$i][0], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $nodes[] = ['off' => $off, 'len' => strlen($full), 'text' => $text, 'cstart' => strlen($concat), 'edits' => []];
            $concat .= $text;
        }

        // Собрать замены по ИСХОДНОМУ сквозному тексту: [позиция, длина, значение].
        $repl = [];
        foreach ($literals as $search => $value) {
            $search = (string) $search; $value = (string) $value;
            if ($search === '' || $value === '' || $value === $search) { continue; }
            $p = 0;
            while (($pos = strpos($concat, $search, $p)) !== false) {
                $repl[] = [$pos, strlen($search), $value];
                $p = $pos + strlen($search);
            }
        }
        $map = [];
        foreach ($placeholders as $k => $v) { $map[self::norm((string) $k)] = (string) $v; }
        if (preg_match_all('/\{([^{}]{1,200})\}/su', $concat, $pm, PREG_OFFSET_CAPTURE)) {
            foreach ($pm[0] as $i => [$full, $pos]) {
                $key = self::norm($pm[1][$i][0]);
                if (array_key_exists($key, $map)) { $repl[] = [$pos, strlen($full), $map[$key]]; }
            }
        }
        if (!$repl) { return $xml; }

        // Непересекающиеся замены по возрастанию позиции.
        usort($repl, fn($a, $b) => $a[0] <=> $b[0]);
        $applied = []; $busyTo = -1;
        foreach ($repl as $r) {
            if ($r[0] >= $busyTo) { $applied[] = $r; $busyTo = $r[0] + $r[1]; }
        }

        // Разнести каждую замену по затронутым узлам: значение — в первый узел, хвосты — пустые.
        foreach ($applied as [$pos, $len, $value]) {
            $end = $pos + $len;
            $first = true;
            foreach ($nodes as $i => $n) {
                $ns = $n['cstart']; $ne = $ns + strlen($n['text']);
                if ($ne <= $pos || $ns >= $end) { continue; }
                $localStart = max($pos, $ns) - $ns;
                $localLen   = min($end, $ne) - max($pos, $ns);
                $nodes[$i]['edits'][] = [$localStart, $localLen, $first ? $value : ''];
                $first = false;
            }
        }

        // Применить правки внутри узлов (с конца, чтобы не сдвигать локальные позиции).
        foreach ($nodes as $i => $n) {
            if (!$n['edits']) { continue; }
            usort($nodes[$i]['edits'], fn($a, $b) => $b[0] <=> $a[0]);
            $t = $n['text'];
            foreach ($nodes[$i]['edits'] as [$ls, $ll, $val]) {
                $t = substr($t, 0, $ls) . $val . substr($t, $ls + $ll);
            }
            $nodes[$i]['text'] = $t;
            $nodes[$i]['changed'] = true;
        }

        // Пересобрать XML (с конца, чтобы офсеты исходного XML оставались верными).
        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            if (empty($nodes[$i]['changed'])) { continue; }
            $newNode = '<w:t xml:space="preserve">' . htmlspecialchars($nodes[$i]['text'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t>';
            $xml = substr($xml, 0, $nodes[$i]['off']) . $newNode . substr($xml, $nodes[$i]['off'] + $nodes[$i]['len']);
        }
        return $xml;
    }
}
