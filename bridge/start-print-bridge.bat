@echo off
rem Keeps the print bridge running on Windows. Edit the two values below, then see PANDUAN-WINDOWS.md.
set PRINT_BRIDGE_URL=https://ALAMAT-POS-ANDA
set PRINT_BRIDGE_TOKEN=pb_GANTI_DENGAN_TOKEN

rem Some Windows setups have node on the PATH of PowerShell but not of cmd (or of the scheduled task); fall back to the default install folder.
set NODE=node
where node >nul 2>nul || set "NODE=%ProgramFiles%\nodejs\node.exe"

cd /d "%~dp0"
:loop
"%NODE%" print-bridge.mjs >> print-bridge.log 2>&1
rem Exit code 2 means the token was rejected: stop instead of retrying forever.
if %errorlevel%==2 exit /b 2
ping -n 6 127.0.0.1 >nul
goto loop
