@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo === Uchet: zapusk servera ===

rem Prefer the BUNDLED php (has pdo_sqlite). Fall back to system php only if missing.
set "PHP=%~dp0tools\php\php.exe"
if not exist "%PHP%" (
  where php >nul 2>nul
  if errorlevel 1 (
    echo PHP ne nayden: net ni tools\php\php.exe, ni sistemnogo php.
    pause
    exit /b 1
  )
  set "PHP=php"
)

echo PHP: %PHP%

if not exist "storage\database.sqlite" (
  echo Sozdayu bazu dannyh...
  "%PHP%" database\migrate.php
  "%PHP%" database\seed.php
)

echo Otkryvayu http://localhost:8000
start "" http://localhost:8000
"%PHP%" -S localhost:8000 -t public router.php
