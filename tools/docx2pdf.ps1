<#
  docx2pdf.ps1 — конвертация ходатайств (DOCX на бланке МИД) в один PDF силами Word.
  Сохраняет вид как в Word (в отличие от HTML-печати, которая «съезжает»).

  Параметры:
    -InDir <папка с *.docx>   файлы конвертируются по имени (0001.docx, 0002.docx …)
    -Out   <путь к .pdf>      результат — один общий PDF, каждое ходатайство с новой страницы

  Безопасность:
    • работает только с локальными временными файлами (без Mark-of-the-Web → без Protected View);
    • AutomationSecurity = msoAutomationSecurityForceDisable и DisplayAlerts = none —
      подавляют диалоги без изменения глобальных настроек Word;
    • в конце закрывается ТОЛЬКО тот экземпляр Word, который мы запустили
      (по разнице PID), открытые документы пользователя не трогаются.
#>
param(
    [Parameter(Mandatory = $true)][string]$InDir,
    [Parameter(Mandatory = $true)][string]$Out
)

$ErrorActionPreference = 'Stop'
$wdFormatPDF = 17

$files = Get-ChildItem -Path $InDir -Filter *.docx -ErrorAction SilentlyContinue | Sort-Object Name
if (-not $files -or $files.Count -eq 0) { Write-Output 'NO_DOCX'; exit 2 }

# Какие WINWORD уже открыты (их не трогаем).
$before = @(Get-Process -Name WINWORD -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id)

$word = New-Object -ComObject Word.Application
$myPids = @()
try {
    $word.Visible = $false
    try { $word.DisplayAlerts = 0 } catch {}            # wdAlertsNone
    try { $word.AutomationSecurity = 3 } catch {}        # msoAutomationSecurityForceDisable

    # PID именно нашего экземпляра.
    $after = @(Get-Process -Name WINWORD -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id)
    $myPids = $after | Where-Object { $before -notcontains $_ }

    # Конвертируется один файл (приложение собирает многостраничный DOCX заранее на уровне
    # OOXML и передаёт его сюда). Это самый надёжный путь — Open + ExportAsFixedFormat.
    # Если файлов несколько (запасной режим docxToPdfEach по одному) — берём первый из переданной папки.
    $doc = $word.Documents.Open($files[0].FullName, $false, $true)  # ConfirmConversions=false, ReadOnly=true
    $doc.ExportAsFixedFormat($Out, $wdFormatPDF)
    $doc.Close($false)
    Write-Output 'OK'
}
catch {
    Write-Output ('ERR ' + $_.Exception.Message)
    exit 1
}
finally {
    try { $word.Quit() } catch {}
    try { [System.Runtime.InteropServices.Marshal]::ReleaseComObject($word) | Out-Null } catch {}
    Start-Sleep -Milliseconds 300
    foreach ($p in $myPids) {
        try { Get-Process -Id $p -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue } catch {}
    }
}
