@echo off
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul
title Ucetni prehled - lokalni spusteni
cd /d "%~dp0"

set "PHP_EXE=%CD%\runtime\php\php.exe"
set "DB_FILE=%CD%\data\ucetni-prehled.sqlite"

if not exist "%PHP_EXE%" (
    echo.
    echo [CHYBA] Chybi pribaleny PHP runtime.
    echo Znovu stahnete a kompletne rozbalte vydani Ucetni prehled.
    echo.
    pause
    exit /b 1
)

if not exist "vendor\autoload.php" (
    echo.
    echo [CHYBA] Balicek Ucetni prehled neni kompletni - chybi provozni soubory.
    echo Znovu stahnete a kompletne rozbalte vydani Ucetni prehled.
    echo.
    pause
    exit /b 1
)

if not exist "public\build\manifest.json" (
    echo.
    echo [CHYBA] Balicek Ucetni prehled neni kompletni - chybi sestavene rozhrani.
    echo Znovu stahnete a kompletne rozbalte vydani Ucetni prehled.
    echo.
    pause
    exit /b 1
)

if not exist ".env" copy /Y ".env.example" ".env" >nul
if not exist "data" mkdir "data"
if not exist "%DB_FILE%" type nul > "%DB_FILE%"

rem Vsechny provozni hodnoty plati jen pro tento proces a jeho lokalni server.
set "APP_ENV=local"
set "APP_DEBUG=false"
set "LOG_LEVEL=error"
set "DB_CONNECTION=sqlite"
set "DB_DATABASE=%DB_FILE%"
set "SESSION_DRIVER=file"
set "CACHE_STORE=file"
set "QUEUE_CONNECTION=sync"
set "MAIL_MAILER=log"
set "HIBP_ENABLED=false"

findstr /R /C:"^APP_KEY=base64:" ".env" >nul
if errorlevel 1 (
    "%PHP_EXE%" artisan key:generate --force
    if errorlevel 1 goto :setup_error
)

findstr /R /C:"^UCETNI_PREHLED_ENCRYPTION_KEY=base64:" ".env" >nul
if errorlevel 1 (
    echo.
    echo Vytvarim mistni sifrovaci klic dat. Zalozni frazi si uschovejte.
    "%PHP_EXE%" artisan ucetni-prehled:key-generate
    if errorlevel 1 goto :setup_error
)

"%PHP_EXE%" artisan migrate --force --no-interaction
if errorlevel 1 goto :setup_error

rem Laravel si pri kazdem spusteni ulozi konfiguraci pro aktualni mistni cestu
rem a port. Pri klikani v menu se pak konfigurace znovu nesestavuje.
"%PHP_EXE%" artisan config:cache --no-interaction >nul
if errorlevel 1 goto :setup_error

"%PHP_EXE%" artisan route:cache --no-interaction >nul
if errorlevel 1 goto :setup_error

set "PORT="
for /L %%P in (8090,1,8099) do (
    if not defined PORT (
        rem Kontrolujeme vsechny naslouchajici adresy. Jiny program muze port
        rem obsadit napriklad pres 0.0.0.0 nebo IPv6, ne jen pres 127.0.0.1.
        netstat -ano -p TCP | findstr /R /C:":%%P .*LISTENING" >nul
        if errorlevel 1 set "PORT=%%P"
    )
)

if not defined PORT (
    echo.
    echo [CHYBA] Porty 8090 az 8099 jsou obsazene.
    pause
    exit /b 1
)

set "APP_URL=http://127.0.0.1:%PORT%"
echo.
echo Spoustim Ucetni prehled na %APP_URL%
echo Server je dostupny pouze na tomto pocitaci.

rem PHP CLI cache na Windows muze drzet stary kod i po novem spusteni START.bat.
rem Laravel ma vlastni cache konfigurace, tras a pohledu; ta zustava aktivni.
start "UcetniPrehledLocalServer" /min "%PHP_EXE%" -d opcache.enable=0 -d opcache.enable_cli=0 -S 127.0.0.1:%PORT% local-router.php
timeout /t 2 /nobreak >nul

rem Pokud se server nepodarilo spustit, neotevirejme omylem jinou aplikaci,
rem ktera by mohla bezet na stejnem portu.
netstat -ano -p TCP | findstr /R /C:":%PORT% .*LISTENING" >nul
if errorlevel 1 (
    echo.
    echo [CHYBA] Lokalni server Ucetni prehled se nepodarilo spustit.
    echo Zkuste prosim start.bat znovu. Pokud chyba trva, prectete README.txt.
    pause
    exit /b 1
)

rem Pri prvnim otevreni by Laravel musel postupne nacist pohledy vsech sekci.
rem Zahrejeme je jednou pri startu, aby klikani v menu bylo bez zbytecne prodlevy.
echo Pripravuji lokalni rozhrani pro rychlejsi klikani v menu...
powershell -NoProfile -Command "$ErrorActionPreference='SilentlyContinue'; $session=New-Object Microsoft.PowerShell.Commands.WebRequestSession; foreach ($path in @('/prehled','/faktury','/klienti','/banka','/ucetnictvi','/dane','/nastaveni')) { $null=Invoke-WebRequest -UseBasicParsing -WebSession $session -Uri ('%APP_URL%'+$path) -TimeoutSec 10 }" >nul 2>&1

start "" "%APP_URL%/"

echo.
echo Ucetni prehled bezi na %APP_URL%/
echo Pro ukonceni spustte stop.bat.
timeout /t 4 /nobreak >nul
endlocal
exit /b 0

:setup_error
echo.
echo [CHYBA] Inicializace lokalni aplikace se nezdarila.
pause
exit /b 1
