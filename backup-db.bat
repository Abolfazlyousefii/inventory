
@echo off

set PROJECT=C:\laragon\www\inventory
set BACKUP_DIR=E:\inventory-db-backups

set MYSQLDUMP=C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe

set DB_NAME=inventory
set DB_USER=root
set DB_PASS=

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HH-mm-ss"') do set NOW=%%i

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

if "%DB_PASS%"=="" (
    "%MYSQLDUMP%" -u %DB_USER% --single-transaction --routines --triggers --events %DB_NAME% > "%BACKUP_DIR%\%DB_NAME%_%NOW%.sql"
) else (
    "%MYSQLDUMP%" -u %DB_USER% --password=%DB_PASS% --single-transaction --routines --triggers --events %DB_NAME% > "%BACKUP_DIR%\%DB_NAME%_%NOW%.sql"
)

powershell -NoProfile -Command "Compress-Archive -Path '%BACKUP_DIR%\%DB_NAME%_%NOW%.sql' -DestinationPath '%BACKUP_DIR%\%DB_NAME%_%NOW%.zip' -Force; Remove-Item '%BACKUP_DIR%\%DB_NAME%_%NOW%.sql'"