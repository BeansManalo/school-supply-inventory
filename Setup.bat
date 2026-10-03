@echo off
setlocal
cd /d "%~dp0"
fltmc >nul 2>&1 || if not "%~1"=="--elevated" (
    powershell -NoProfile -Command "Start-Process -Verb RunAs -FilePath '%~f0' -ArgumentList '--elevated %*'" || pause
    exit /b
)
if not exist "%~dp0setup.php" (
    echo setup.php is not next to this file. Extract the whole zip first, then run Setup.bat again.
    pause
    exit /b 1
)
set "X="
for %%D in (C D E F G H) do if not defined X if exist "%%D:\xampp\php\php.exe" set "X=%%D:\xampp"
if not defined X (
    echo XAMPP was not found in the usual place. Get it from https://www.apachefriends.org
    set /p "X=If it is installed somewhere else, type that folder here, or press Enter to quit: "
)
if not defined X exit /b 1
set "X=%X:"=%"
if "%X:~-1%"=="\" set "X=%X:~0,-1%"
if not exist "%X%\php\php.exe" (
    echo "%X%\php\php.exe" was not found. Is XAMPP installed in that folder?
    pause
    exit /b 1
)
"%X%\php\php.exe" "%~dp0setup.php" "%X%" %*
echo.
pause
