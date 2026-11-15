@echo off
echo Intimora Local Admin
echo ====================
echo.
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
