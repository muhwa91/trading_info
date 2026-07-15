@echo off
chcp 65001 >nul
title trading_info Runner
setlocal

:: 이 배치 파일이 있는 폴더를 기준 경로로 사용 (프로젝트를 옮겨도 안 깨짐)
set "BASE=%~dp0"
set "BACKEND=%BASE%backend"
set "FRONTEND=%BASE%frontend"

echo ====================================================
echo  trading_info 주식 모니터링 서비스 시작 중...
echo ====================================================

:: 0. 멱등 재시작 보장 — 먼저 기존 백엔드 PHP 프로세스를 커맨드라인 기준으로 전수 종료.
::    (stop_trading_info.bat 와 동일 로직. 포트 리스너 kill 은 non-listening stale 데몬을
::     놓쳐 구 코드가 공유 캐시를 되쓰는 사고가 있었음.)
echo [..] 기존 백엔드 PHP 프로세스 정리 중 (artisan serve / agent:serve / server.php)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$b=[regex]::Escape('%BACKEND%'); Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -and ($_.CommandLine -match 'artisan (serve|agent:serve)' -or $_.CommandLine -match $b) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"
timeout /t 1 /nobreak > nul

:: 1. 백엔드 API 서버 (Port 8000) — 위에서 정리했으므로 무조건 새로 시작
echo [..] Laravel API 서버 시작 중...
start "trading_info API" /min cmd /c "cd /d "%BACKEND%" && php artisan serve --port=8000"

:: 2. 백엔드 웹소켓 서버 (Port 8080) — 위에서 정리했으므로 무조건 새로 시작
echo [..] WebSocket 에이전트 서버 시작 중...
start "trading_info WS" /min cmd /c "cd /d "%BACKEND%" && php artisan agent:serve"

:: 3. 프론트엔드 개발 서버 (Port 5173) — node 프로세스라 정리 대상 아님, 중복 실행만 방지
netstat -o -n -a | findstr :5173 > nul
if %errorlevel% equ 0 (
    echo [OK] Vite 개발 서버가 이미 실행 중입니다. ^(Port 5173^)
) else (
    echo [..] Vite 프론트엔드 개발 서버 시작 중...
    start "trading_info Frontend" /min cmd /c "cd /d "%FRONTEND%" && npm run dev"
)

echo ----------------------------------------------------
echo 서버가 구동될 때까지 잠시 대기합니다 ^(3초^)...
timeout /t 3 /nobreak > nul

echo [..] 브라우저에서 대시보드를 엽니다...
start http://localhost:5173

echo ====================================================
echo  실행이 완료되었습니다. 이 창은 5초 후 닫힙니다.
echo ====================================================
timeout /t 5 > nul
endlocal
