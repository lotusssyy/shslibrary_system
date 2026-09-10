@echo off
REM ============================================
REM SHS Library - XAMPP + ngrok Startup
REM ============================================
REM This script starts Apache/MySQL via XAMPP
REM and creates a public ngrok tunnel.
REM
REM BEFORE FIRST USE:
REM 1. Sign up at https://dashboard.ngrok.com/signup
REM 2. Get your auth token from https://dashboard.ngrok.com/get-started/your-authtoken
REM 3. Run: ngrok config add-authtoken YOUR_TOKEN
REM    (only needed once)
REM ============================================

echo.
echo ========================================
echo   SHS Library - Starting XAMPP + ngrok
echo ========================================
echo.

REM --- Start XAMPP services ---
echo [1/3] Starting Apache and MySQL...
"C:\xampp\xampp_start.exe"

REM Wait for services to be ready
timeout /t 3 /nobreak >nul

echo [2/3] Apache and MySQL started.

REM --- Start ngrok tunnel ---
echo [3/3] Starting ngrok tunnel on port 80...
echo.
echo ========================================
echo   Share this URL with your students:
echo.
start "" "C:\Users\9rmag\Desktop\ngrok.exe" http http://localhost:80
timeout /t 3 /nobreak >nul

echo.
echo   Open http://localhost:4040 to see ngrok dashboard
echo ========================================
echo.
echo Press Ctrl+C to stop. Close this window to stop everything.
pause
