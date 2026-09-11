@echo off
rem ============================================================
rem  Crawler Admin 一键启动
rem  - 后端: goKit crawler-api  (http://localhost:8088)
rem  - 前端: apps/web Workbench (http://localhost:5173)
rem  依赖: Go 1.23+ / Node 18+ / 已启动的 MySQL + Redis
rem ============================================================
setlocal
chcp 65001 >nul

set "ROOT=%~dp0"
set "GO_DIR=%ROOT%goKit"
set "WEB_DIR=%ROOT%apps\web"
set "CONFIG=configs/config.yaml"
set "API_ADDR=:8088"

echo ============================================================
echo   Crawler Admin - 数据采集管理平台
echo ------------------------------------------------------------
echo   后端 API : http://localhost:8088/api/v1
echo   前端控制台: http://localhost:5173
echo ============================================================
echo.

where go >nul 2>nul
if errorlevel 1 (
  echo [ERROR] 未找到 go，请安装 Go 1.23+ 并加入 PATH
  pause
  exit /b 1
)
where node >nul 2>nul
if errorlevel 1 (
  echo [ERROR] 未找到 node，请安装 Node 18+ 并加入 PATH
  pause
  exit /b 1
)
if not exist "%GO_DIR%\%CONFIG%" (
  echo [ERROR] 缺少配置文件 %GO_DIR%\%CONFIG%
  pause
  exit /b 1
)
if not exist "%WEB_DIR%\package.json" (
  echo [ERROR] 缺少前端工程 %WEB_DIR%
  pause
  exit /b 1
)

if not exist "%WEB_DIR%\node_modules" (
  echo [setup] 首次运行，安装前端依赖 ...
  pushd "%WEB_DIR%"
  call npm install
  popd
  if errorlevel 1 (
    echo [ERROR] npm install 失败
    pause
    exit /b 1
  )
)

echo [start] 启动后端 crawler-api ...
start "crawler-api" /D "%GO_DIR%" cmd /k "go run ./cmd/crawler-api -config %CONFIG% -addr %API_ADDR%"

echo [start] 启动前端 Workbench ...
start "crawler-workbench" /D "%WEB_DIR%" cmd /k "npm run dev"

echo.
echo [OK] 已在新窗口启动前后端。关闭对应窗口即可停止服务。
echo      首次启动后端会自动建库建表；请确保 MySQL / Redis 已运行。
echo.
endlocal
