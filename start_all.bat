@echo off
rem ============================================================
rem  Crawler Admin - one-click start
rem  Backend: goKit crawler-api  -> http://localhost:8088
rem  Frontend: apps/web Workbench -> http://localhost:5173
rem  Requires: Go 1.23+, Node 18+, running MySQL and Redis
rem ============================================================
setlocal

set "ROOT=%~dp0"
set "GO_DIR=%ROOT%goKit"
set "WEB_DIR=%ROOT%apps\web"
set "CONFIG=configs/config.yaml"
set "API_ADDR=:8088"

echo ============================================================
echo   Crawler Admin - Data Collection Platform
echo ------------------------------------------------------------
echo   API  : http://localhost:8088/api/v1
echo   Web  : http://localhost:5173
echo ============================================================
echo.

where go >nul 2>nul
if errorlevel 1 (
  echo [ERROR] go not found. Install Go 1.23+ and add to PATH.
  pause
  exit /b 1
)
where node >nul 2>nul
if errorlevel 1 (
  echo [ERROR] node not found. Install Node 18+ and add to PATH.
  pause
  exit /b 1
)
if not exist "%GO_DIR%\%CONFIG%" (
  echo [ERROR] config not found: %GO_DIR%\%CONFIG%
  pause
  exit /b 1
)
if not exist "%WEB_DIR%\package.json" (
  echo [ERROR] frontend project not found: %WEB_DIR%
  pause
  exit /b 1
)

if not exist "%WEB_DIR%\node_modules" (
  echo [setup] First run: installing frontend dependencies ...
  pushd "%WEB_DIR%"
  call npm install
  popd
  if errorlevel 1 (
    echo [ERROR] npm install failed.
    pause
    exit /b 1
  )
)

echo [start] Starting crawler-api backend ...
cd /d "%GO_DIR%"
start "crawler-api" cmd /k "go run ./cmd/crawler-api -config %CONFIG% -addr %API_ADDR%"

echo [start] Starting Workbench frontend ...
cd /d "%WEB_DIR%"
start "crawler-workbench" cmd /k "npm run dev"

echo.
echo [OK] Both services started in new windows. Close those windows to stop.
echo      First backend start will auto-create database/tables.
echo      Make sure MySQL and Redis are running.
echo.
endlocal
