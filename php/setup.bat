@echo off
echo ==========================================
echo   Intimora PHP Backend - Setup
echo ==========================================
echo.

REM Check if PHP is installed
php -v >nul 2>&1
if %errorlevel% neq 0 (
    echo PHP not found! Installing...
    echo Download from: https://windows.php.net/download/
    echo Install PHP 8.1+ and add to PATH
    pause
    exit /b 1
)

echo PHP found!
echo.

REM Check if composer.phar exists
if not exist "C:\Users\HP\composer.phar" (
    echo Composer not found! Downloading...
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php
    php -r "unlink('composer-setup.php');"
    echo Composer installed!
    echo.
)

echo Composer found!
echo.

REM Install dependencies
echo Installing dependencies...
php C:\Users\HP\composer.phar install

if %errorlevel% neq 0 (
    echo Error installing dependencies!
    pause
    exit /b 1
)

echo.
echo ==========================================
echo   Setup Complete!
echo ==========================================
echo.
echo Vendor folder created!
echo Now upload all files to InfinityFree htdocs/ folder
echo using FileZilla.
echo.
echo Files to upload:
echo   - vendor/ folder (IMPORTANT!)
echo   - .htaccess
echo   - config.php
echo   - index.php
echo   - store.php
echo   - telegram.php
echo   - mtproto.php
echo   - db.php
echo   - composer.json
echo   - README.md
echo.
pause
