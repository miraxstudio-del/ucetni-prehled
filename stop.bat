@echo off
setlocal EnableExtensions
title Ucetni prehled - ukonceni

rem Ukonci pouze PHP pribaleny prave v teto slozce Ucetni prehled, nikdy XAMPP ani jiny PHP.
set "UCETNI_PREHLED_STOP_ROOT=%~dp0"
powershell -NoProfile -Command "$php = [System.IO.Path]::GetFullPath((Join-Path $env:UCETNI_PREHLED_STOP_ROOT 'runtime\php\php.exe')); $servers = @(Get-Process php -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $php }); if ($servers.Count -eq 0) { exit 2 }; $servers | Stop-Process -Force; exit 0" >nul 2>&1

if %ERRORLEVEL%==0 (
    echo Ucetni prehled byl ukoncen. Data zustala bezpecne ulozena ve slozce data.
) else (
    echo Ucetni prehled pravdepodobne uz nebezel.
)

timeout /t 3 /nobreak >nul
endlocal
