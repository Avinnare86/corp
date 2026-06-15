<?php
namespace App\Services;

/**
 * Образ документа: конвертация вложения (Word/Excel) в PDF для предпросмотра
 * в карточке СЭД. Использует установленный Microsoft Office через COM
 * (Windows; extension=com_dotnet). Результат кэшируется per-версия в
 * storage/uploads/previews/{doc_files.id}.pdf — повторные открытия мгновенны.
 * Если Office/COM недоступны или формат неизвестен — возвращает null,
 * страница показывает ссылку на скачивание.
 */
class PdfPreview
{
    public const WORD_EXT  = ['doc', 'docx', 'rtf', 'odt', 'txt'];
    public const EXCEL_EXT = ['xls', 'xlsx', 'xlsm', 'csv', 'ods'];
    public const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];

    private static function dir(): string
    {
        $d = dirname(__DIR__, 2) . '/storage/uploads/previews';
        if (!is_dir($d)) { @mkdir($d, 0775, true); }
        return $d;
    }

    /** Абсолютный путь к PDF-образу версии вложения (конвертирует при первом обращении). */
    public static function ensure(array $file, string $uploadDir): ?string
    {
        $src = $uploadDir . '/' . $file['stored_name'];
        if (!is_file($src)) { return null; }
        $ext = strtolower(pathinfo((string) $file['orig_name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf') { return $src; } // уже PDF — отдаём как есть

        $dst = self::dir() . '/' . (int) $file['id'] . '.pdf';
        if (is_file($dst) && filesize($dst) > 0) { return $dst; }
        if (!class_exists('\\COM')) { return null; }

        $srcW = str_replace('/', '\\', (string) realpath($src));
        $dstW = str_replace('/', '\\', $dst);
        @set_time_limit(180);

        if (in_array($ext, self::WORD_EXT, true))  { return self::viaWord($srcW, $dstW) ? $dst : null; }
        if (in_array($ext, self::EXCEL_EXT, true)) { return self::viaExcel($srcW, $dstW) ? $dst : null; }
        return null;
    }

    private static function viaWord(string $src, string $dst): bool
    {
        $word = null; $doc = null;
        try {
            $word = new \COM('Word.Application');
            $word->Visible = false;
            $word->DisplayAlerts = 0;
            $doc = $word->Documents->Open($src, false, true); // без подтверждений, только чтение
            $doc->ExportAsFixedFormat($dst, 17);              // 17 = wdExportFormatPDF
            $doc->Close(0);
            $word->Quit(0);
            return is_file($dst) && filesize($dst) > 0;
        } catch (\Throwable $e) {
            try { if ($doc) { $doc->Close(0); } } catch (\Throwable $e2) {}
            try { if ($word) { $word->Quit(0); } } catch (\Throwable $e2) {}
            Audit::log('PDF_PREVIEW_FAIL', 'Образ документа: ошибка конвертации Word', $e->getMessage());
            return false;
        } finally {
            unset($doc, $word);
        }
    }

    private static function viaExcel(string $src, string $dst): bool
    {
        $xl = null; $wb = null;
        try {
            $xl = new \COM('Excel.Application');
            $xl->Visible = false;
            $xl->DisplayAlerts = false;
            $wb = $xl->Workbooks->Open($src, 0, true);
            $wb->ExportAsFixedFormat(0, $dst); // 0 = xlTypePDF
            $wb->Close(false);
            $xl->Quit();
            return is_file($dst) && filesize($dst) > 0;
        } catch (\Throwable $e) {
            try { if ($wb) { $wb->Close(false); } } catch (\Throwable $e2) {}
            try { if ($xl) { $xl->Quit(); } } catch (\Throwable $e2) {}
            Audit::log('PDF_PREVIEW_FAIL', 'Образ документа: ошибка конвертации Excel', $e->getMessage());
            return false;
        } finally {
            unset($wb, $xl);
        }
    }
}
