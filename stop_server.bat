@echo off
REM ============================================
REM SHS Library - Stop Server
REM ============================================
REM Stops ngrok and XAMPP services.
REM ============================================

echo Stopping ngrok...
taskkill /F /IM ngrok.exe >nul 2>&1

echo Stopping XAMPP...
"C:\xampp\xampp_stop.exe"

echo.
echo Server stopped.
timeout /t 2 /nobreak >nul
