@echo off
cd /d "%~dp0"
echo Intimora Local Admin
echo ====================
echo.
node -e "fetch('http://127.0.0.1:3000/api/health',{signal:AbortSignal.timeout(2000)}).then(async r=>{const j=await r.json();process.exit(r.ok && j.ok && j.data?.status==='up'?0:1)}).catch(()=>process.exit(1))" >nul 2>&1
if not errorlevel 1 (
    echo Panel already running. Opening existing panel...
    start "" "http://localhost:3000"
    exit /b 0
)
if not exist node_modules (
    echo Installing dependencies...
    call npm install
)
echo Checking teleproto version...
node -e "process.exit(require('teleproto/package.json').version==='1.230.1'?0:1)" || (
    echo Installing teleproto 1.230.1 ...
    call npm install teleproto@1.230.1 --save-exact
)
echo.
echo Open http://localhost:3000 in your browser
echo Press Ctrl+C to stop
echo.
node server.js
if errorlevel 1 (
    echo.
    echo Server start nahi hua. Upar ka error dekho.
    pause
)
