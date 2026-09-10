@echo off
REM ============================================
REM SHS Library - Overdue Book Notifications
REM ============================================
REM This batch file runs the notification script.
REM It is designed to be called by Windows Task Scheduler.
REM
REM SETUP INSTRUCTIONS:
REM 1. Open Task Scheduler (taskschd.msc)
REM 2. Click "Create Basic Task..."
REM 3. Name: "SHS Library - Send Overdue Notifications"
REM 4. Trigger: Daily, repeat every 1 hour for 1 day
REM 5. Action: Start a program
REM    Program/script: powershell.exe
REM    Add arguments: -ExecutionPolicy Bypass -File "%~dp0run_notifications.ps1"
REM 6. Finish, then right-click the task -> Properties:
REM    - Check "Run whether user is logged on or not"
REM    - Check "Run with highest privileges"
REM    - Under Conditions, uncheck "Start only if on AC power"
REM 7. Enter your Windows password when prompted
REM ============================================

powershell.exe -ExecutionPolicy Bypass -File "%~dp0run_notifications.ps1"
