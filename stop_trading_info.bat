@echo off
title trading_info Stopper
echo ====================================================
echo  trading_info 주식 모니터링 서비스 종료 중...
echo ====================================================

:: Port 8000 (Laravel API)
echo [..] Laravel API 서버 종료 시도 중 (Port 8000)...
for /f "tokens=5" %%a in ('netstat -aon ^| findstr :8000') do (
    taskkill /f /pid %%a >nul 2>&1
)

:: Port 8080 (WebSocket)
echo [..] WebSocket 에이전트 서버 종료 시도 중 (Port 8080)...
for /f "tokens=5" %%a in ('netstat -aon ^| findstr :8080') do (
    taskkill /f /pid %%a >nul 2>&1
)

:: Port 5173 (Vite Frontend)
echo [..] Vite 프론트엔드 서버 종료 시도 중 (Port 5173)...
for /f "tokens=5" %%a in ('netstat -aon ^| findstr :5173') do (
    taskkill /f /pid %%a >nul 2>&1
)

echo ====================================================
echo  모든 서비스 프로세스가 종료되었습니다.
echo  이 창은 3초 후 자동 종료됩니다.
echo ====================================================
timeout /t 3 /nobreak > nul
