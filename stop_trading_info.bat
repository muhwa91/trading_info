@echo off
chcp 65001 >nul
title trading_info Stopper
setlocal

:: 이 배치 파일이 있는 폴더를 기준 경로로 사용
set "BASE=%~dp0"
set "BACKEND=%BASE%backend"

echo ====================================================
echo  trading_info 주식 모니터링 서비스 종료 중...
echo ====================================================

:: 1+2. 백엔드 PHP 프로세스 전수 종료 (커맨드라인 기준)
::   포트 리스너 kill 은 non-listening stale 데몬(agent:serve 등)을 놓쳐
::   구 코드가 공유 캐시를 계속 되쓰는 사고가 있었음. 그래서 커맨드라인으로 전수 kill.
::   필터: artisan serve / agent:serve(이 프로젝트 고유 커맨드) 또는 이 프로젝트 backend 경로(server.php 워커)를
::         포함한 php.exe 만 종료 → 타 PHP 프로젝트 오폭 방지.
::   ※ php.exe 는 "이름" 기준 매칭이라 PHP 경로가 바뀌어도(7.4→8.4) 그대로 동작 — PHP 경로 정의 불필요.
echo [..] 백엔드 PHP 프로세스 종료 중 (artisan serve / agent:serve / server.php)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$b=[regex]::Escape('%BACKEND%'); Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -and ($_.CommandLine -match 'artisan (serve|agent:serve)' -or $_.CommandLine -match $b) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"

:: 3. Port 5173 (Vite Frontend — node 프로세스라 포트 기준 유지)
echo [..] Vite 프론트엔드 서버 종료 시도 중 (Port 5173)...
for /f "tokens=5" %%a in ('netstat -aon ^| findstr :5173') do (
    taskkill /f /pid %%a >nul 2>&1
)

echo ====================================================
echo  모든 서비스 프로세스가 종료되었습니다.
echo  이 창은 3초 후 자동 종료됩니다.
echo ====================================================
timeout /t 3 /nobreak > nul
endlocal
