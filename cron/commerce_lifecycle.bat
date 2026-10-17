@echo off
REM WorkToGo — commerce lifecycle cron (Paint PB6), Windows Task Scheduler helper.
REM Runs the offer + quote timeout sweeps once. Schedule it to repeat every 1 min:
REM
REM   schtasks /Create /TN "WTG Commerce Lifecycle" /SC MINUTE /MO 1 ^
REM     /TR "\"%~dp0commerce_lifecycle.bat\"" /F
REM
REM   (delete with:  schtasks /Delete /TN "WTG Commerce Lifecycle" /F )
REM
REM Uses php from PATH; if php is not on PATH, set PHP_BIN to your binary, e.g.
REM   set "PHP_BIN=C:\xampp\php\php.exe"
if "%PHP_BIN%"=="" set "PHP_BIN=php"
"%PHP_BIN%" "%~dp0commerce_lifecycle.php"
