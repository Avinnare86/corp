<?php
namespace App\Services;

/**
 * Конвертация DOCX → PDF «как в Word», без правки самого документа.
 *
 * Движки (по доступности):
 *   1. Microsoft Word (Windows, COM через PowerShell) — если установлен;
 *   2. LibreOffice headless (Linux/Windows, команда soffice) — бесплатно, без MS Office.
 *
 * Несколько ходатайств склеиваются в один PDF:
 *   - Word склеивает сам (мастер-документ);
 *   - для LibreOffice каждое конвертируется отдельно, затем склейка pdfunite /
 *     ghostscript / qpdf (что найдётся). Если склейщика нет — контроллер отдаст
 *     ZIP с отдельными PDF.
 *
 * Безопасность: работаем только с локальными временными файлами (нет Mark-of-the-Web →
 * нет Protected View), сторож по таймауту, у LibreOffice — отдельный профиль на запуск
 * (иначе параллельные запросы дерутся за блокировку профиля).
 */
class OfficeConverter
{
    private const MIN_PDF = 200; // байт; меньше — заведомо мусор

    // ---------- Обнаружение движков ----------

    /** Путь к WINWORD.EXE, если Word установлен (только Windows). */
    public static function wordPath(): ?string
    {
        if (!self::isWindows()) { return null; }
        $candidates = [];
        foreach (['ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432'] as $env) {
            $pf = getenv($env);
            if ($pf) {
                $candidates[] = $pf . '\\Microsoft Office\\root\\Office16\\WINWORD.EXE';
                $candidates[] = $pf . '\\Microsoft Office\\Office16\\WINWORD.EXE';
                $candidates[] = $pf . '\\Microsoft Office\\root\\Office15\\WINWORD.EXE';
            }
        }
        foreach ($candidates as $p) { if (is_file($p)) { return $p; } }
        return null;
    }

    /** Путь к soffice (LibreOffice), если установлен. */
    public static function sofficePath(): ?string
    {
        if (self::isWindows()) {
            foreach (['ProgramFiles', 'ProgramFiles(x86)'] as $env) {
                $pf = getenv($env);
                if ($pf && is_file($pf . '\\LibreOffice\\program\\soffice.exe')) {
                    return $pf . '\\LibreOffice\\program\\soffice.exe';
                }
            }
            return null;
        }
        // Linux/macOS: штатные места + PATH.
        foreach (['/usr/bin/soffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice', '/snap/bin/libreoffice'] as $p) {
            if (is_file($p) && is_executable($p)) { return $p; }
        }
        $w = trim((string) @shell_exec('command -v soffice 2>/dev/null'));
        if ($w !== '' && is_file($w)) { return $w; }
        $w = trim((string) @shell_exec('command -v libreoffice 2>/dev/null'));
        return ($w !== '' && is_file($w)) ? $w : null;
    }

    /** Какой движок будет использован: 'word' | 'soffice' | null. */
    public static function engine(): ?string
    {
        if (self::wordPath() !== null) { return 'word'; }
        if (self::sofficePath() !== null) { return 'soffice'; }
        return null;
    }

    public static function available(): bool
    {
        return self::engine() !== null;
    }

    /** Доступный инструмент склейки PDF (для движка soffice): pdfunite | gs | qpdf | null. */
    public static function mergeTool(): ?string
    {
        if (self::isWindows()) { return null; } // на Windows склейка не нужна (DOCX-XML до Word)
        // pdfunite/qpdf склеивают страницы как есть; gs ПЕРЕрисовывает — его последним.
        foreach (['pdfunite', 'qpdf', 'gs'] as $tool) {
            $w = trim((string) @shell_exec('command -v ' . $tool . ' 2>/dev/null'));
            if ($w !== '') { return $tool; }
        }
        return null;
    }

    // ---------- Конвертация ----------

    /**
     * Набор DOCX → ОДИН общий PDF (каждый документ с новой страницы).
     * null — если движка нет, конвертация не удалась или (для soffice) нечем склеить >1 файла.
     */
    public static function docxToPdf(array $docxBinaries, int $timeoutSec = 240): ?string
    {
        $engine = self::engine();
        if ($engine === 'word') { return self::viaWord($docxBinaries, $timeoutSec); }
        if ($engine === 'soffice') {
            $pdfs = self::viaSoffice($docxBinaries, $timeoutSec);
            if ($pdfs === null) { return null; }
            if (count($pdfs) === 1) { return reset($pdfs); }
            return self::mergePdfs($pdfs);
        }
        return null;
    }

    /**
     * Набор DOCX → отдельный PDF на каждый документ (имена сохраняются по индексам).
     * Используется контроллером, когда общий PDF собрать нечем (нет склейщика) — тогда ZIP.
     * @return array<int,string>|null PDF-байты в порядке входа
     */
    public static function docxToPdfEach(array $docxBinaries, int $timeoutSec = 240): ?array
    {
        $engine = self::engine();
        if ($engine === 'soffice') { return self::viaSoffice($docxBinaries, $timeoutSec); }
        if ($engine === 'word') {
            // Word: конвертируем по одному (медленнее, но без склейки).
            $out = [];
            foreach ($docxBinaries as $bin) {
                $pdf = self::viaWord([$bin], $timeoutSec);
                if ($pdf === null) { return null; }
                $out[] = $pdf;
            }
            return $out;
        }
        return null;
    }

    /**
     * Любой офисный файл (Word/Excel/RTF/ODT/…) → PDF силами LibreOffice (soffice).
     * Кроссплатформенно; на Windows используется, если установлен LibreOffice (для MS Office
     * есть отдельный COM-путь в PdfPreview). Возвращает PDF-байты или null.
     */
    public static function fileToPdf(string $srcPath, int $timeoutSec = 180): ?string
    {
        $soffice = self::sofficePath();
        if ($soffice === null || !is_file($srcPath)) { return null; }
        $work = self::makeWorkDir();
        if ($work === null) { return null; }

        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION)) ?: 'bin';
        $in  = $work . DIRECTORY_SEPARATOR . 'in.' . $ext;
        if (!@copy($srcPath, $in)) { self::rrmdir($work); return null; }
        $profile = $work . DIRECTORY_SEPARATOR . 'lo_profile';
        @mkdir($profile, 0777, true);

        if (self::isWindows()) {
            $cmd = self::winArg($soffice)
                 . ' --headless --norestore --convert-to pdf:writer_pdf_Export'
                 . ' -env:UserInstallation=' . self::winArg('file:///' . str_replace('\\', '/', $profile))
                 . ' --outdir ' . self::winArg($work) . ' ' . self::winArg($in);
            $guard = 'powershell -NoProfile -ExecutionPolicy Bypass -Command '
                   . self::winArg(
                       '$p = Start-Process -FilePath cmd.exe -ArgumentList \'/c\',' . self::psSingle($cmd)
                       . ' -WindowStyle Hidden -PassThru;'
                       . ' if (-not $p.WaitForExit(' . ($timeoutSec * 1000) . ')) { try { $p.Kill() } catch {} ; exit 124 }'
                     );
            @exec($guard . ' 2>&1');
        } else {
            $cmd = 'cd ' . escapeshellarg($work) . ' && HOME=' . escapeshellarg($work)
                 . ' timeout ' . (int) $timeoutSec . ' ' . escapeshellarg($soffice)
                 . ' --headless --norestore --convert-to pdf:writer_pdf_Export'
                 . ' -env:UserInstallation=file://' . escapeshellarg($profile)
                 . ' --outdir ' . escapeshellarg($work) . ' ' . escapeshellarg($in);
            @exec($cmd . ' 2>&1');
        }

        $outPdf = $work . DIRECTORY_SEPARATOR . 'in.pdf';
        $bin = is_file($outPdf) ? @file_get_contents($outPdf) : false;
        self::rrmdir($work);
        return ($bin !== false && strlen($bin) > self::MIN_PDF) ? $bin : null;
    }

    // ---------- Движок 1: Microsoft Word (Windows) ----------

    private static function viaWord(array $docxBinaries, int $timeoutSec): ?string
    {
        $work = self::makeWorkDir();
        if ($work === null) { return null; }
        if (self::writeDocx($work, $docxBinaries) === 0) { self::rrmdir($work); return null; }

        $base   = dirname(__DIR__, 2);
        $out    = $work . '\\out.pdf';
        $script = $base . '\\tools\\docx2pdf.ps1';

        // Самоубивающийся сторож: конвертация фоновым процессом, по таймауту — kill.
        $inner = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . self::winArg($script)
               . ' -InDir ' . self::winArg($work) . ' -Out ' . self::winArg($out);
        $guard = 'powershell -NoProfile -ExecutionPolicy Bypass -Command '
               . self::winArg(
                   '$p = Start-Process -FilePath cmd.exe -ArgumentList \'/c\',' . self::psSingle($inner)
                   . ' -WindowStyle Hidden -PassThru;'
                   . ' if (-not $p.WaitForExit(' . ($timeoutSec * 1000) . ')) { try { $p.Kill() } catch {} ; exit 124 }'
                 );

        $o = []; $code = 0;
        @exec($guard . ' 2>&1', $o, $code);

        $pdf = is_file($out) ? @file_get_contents($out) : false;
        self::rrmdir($work);
        return ($pdf !== false && strlen($pdf) > self::MIN_PDF) ? $pdf : null;
    }

    // ---------- Движок 2: LibreOffice headless ----------

    /** @return array<int,string>|null PDF на каждый входной DOCX (в исходном порядке) */
    private static function viaSoffice(array $docxBinaries, int $timeoutSec): ?array
    {
        $soffice = self::sofficePath();
        $work = self::makeWorkDir();
        if ($soffice === null || $work === null) { return null; }
        $n = self::writeDocx($work, $docxBinaries);
        if ($n === 0) { self::rrmdir($work); return null; }

        // Отдельный профиль LibreOffice на запуск — параллельные запросы не блокируют друг друга.
        $profile = $work . DIRECTORY_SEPARATOR . 'lo_profile';
        @mkdir($profile, 0777, true);

        if (self::isWindows()) {
            $cmd = self::winArg($soffice)
                 . ' --headless --norestore --convert-to pdf:writer_pdf_Export'
                 . ' -env:UserInstallation=' . self::winArg('file:///' . str_replace('\\', '/', $profile))
                 . ' --outdir ' . self::winArg($work) . ' ' . self::winArg($work . '\\*.docx');
            $guard = 'powershell -NoProfile -ExecutionPolicy Bypass -Command '
                   . self::winArg(
                       '$p = Start-Process -FilePath cmd.exe -ArgumentList \'/c\',' . self::psSingle($cmd)
                       . ' -WindowStyle Hidden -PassThru;'
                       . ' if (-not $p.WaitForExit(' . ($timeoutSec * 1000) . ')) { try { $p.Kill() } catch {} ; exit 124 }'
                     );
            @exec($guard . ' 2>&1');
        } else {
            // timeout(1) штатно есть в coreutils; *.docx разворачивает шелл.
            // HOME задаём явно: у пользователя веб-сервера (www-data) его часто нет, без него soffice падает.
            $cmd = 'cd ' . escapeshellarg($work) . ' && HOME=' . escapeshellarg($work)
                 . ' timeout ' . (int) $timeoutSec . ' '
                 . escapeshellarg($soffice)
                 . ' --headless --norestore --convert-to pdf:writer_pdf_Export'
                 . ' -env:UserInstallation=file://' . escapeshellarg($profile)
                 . ' --outdir ' . escapeshellarg($work) . ' *.docx';
            @exec($cmd . ' 2>&1');
        }

        // Собираем результат по номерам входных файлов.
        $pdfs = [];
        for ($i = 1; $i <= $n; $i++) {
            $p = sprintf('%s%s%04d.pdf', $work, DIRECTORY_SEPARATOR, $i);
            $bin = is_file($p) ? @file_get_contents($p) : false;
            if ($bin === false || strlen($bin) <= self::MIN_PDF) { self::rrmdir($work); return null; }
            $pdfs[] = $bin;
        }
        self::rrmdir($work);
        return $pdfs;
    }

    /** Склейка готовых PDF в один (pdfunite / ghostscript / qpdf). */
    public static function mergePdfs(array $pdfBinaries, int $timeoutSec = 120): ?string
    {
        $tool = self::mergeTool();
        if ($tool === null || count($pdfBinaries) === 0) { return null; }
        if (count($pdfBinaries) === 1) { return reset($pdfBinaries); }

        $work = self::makeWorkDir();
        if ($work === null) { return null; }
        $files = [];
        $i = 0;
        foreach ($pdfBinaries as $bin) {
            $p = sprintf('%s/%04d.pdf', $work, ++$i);
            file_put_contents($p, $bin);
            $files[] = escapeshellarg($p);
        }
        $out = $work . '/merged.pdf';

        $cmd = match ($tool) {
            'pdfunite' => 'pdfunite ' . implode(' ', $files) . ' ' . escapeshellarg($out),
            'gs'       => 'gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile=' . escapeshellarg($out) . ' ' . implode(' ', $files),
            'qpdf'     => 'qpdf --empty --pages ' . implode(' ', $files) . ' -- ' . escapeshellarg($out),
        };
        @exec('timeout ' . (int) $timeoutSec . ' ' . $cmd . ' 2>&1');

        $pdf = is_file($out) ? @file_get_contents($out) : false;
        self::rrmdir($work);
        return ($pdf !== false && strlen($pdf) > self::MIN_PDF) ? $pdf : null;
    }

    // ---------- Служебное ----------

    private static function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    private static function makeWorkDir(): ?string
    {
        $work = dirname(__DIR__, 2) . '/storage/tmp/pdf_' . bin2hex(random_bytes(6));
        return (is_dir($work) || @mkdir($work, 0777, true) || is_dir($work)) ? $work : null;
    }

    /** Сохранить DOCX-бинарники как 0001.docx, 0002.docx …; вернуть их число. */
    private static function writeDocx(string $work, array $bins): int
    {
        $i = 0;
        foreach ($bins as $bin) {
            if ($bin === null || $bin === '') { continue; }
            file_put_contents(sprintf('%s/%04d.docx', $work, ++$i), $bin);
        }
        return $i;
    }

    /** Аргумент командной строки Windows в двойных кавычках. */
    private static function winArg(string $s): string
    {
        return '"' . str_replace('"', '\\"', $s) . '"';
    }

    /** Строка в одинарных кавычках PowerShell (экранирование ''). */
    private static function psSingle(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
