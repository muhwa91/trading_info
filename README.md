# Trading Info · 주식 실시간 모니터링 & 포트폴리오 트래커

국내·미국 주식 시세를 **실시간으로 모니터링**하고, 보유 종목 손익을 **원화로 환산**해 보여주는 풀스택 웹 애플리케이션입니다. 외부 증권사 API를 프록시하는 백엔드와 실시간 차트를 그리는 프런트엔드를 직접 설계·구현했습니다.

> Laravel(API·실시간 프록시) + Vue 3(SPA) + 순수 PHP 웹소켓 기반

---

## ✨ 주요 기능

- **실시간 시세** — 순수 PHP Stream Socket 웹소켓 서버로 관심종목 시세를 실시간 전송
- **국내·해외 시세 연동** — 한국투자증권(KIS) API(국내 시세·지수), Yahoo Finance(해외 시세·환율)
- **포트폴리오 손익** — 보유 종목 평가손익을 **원화 환산**하되 **주가 손익과 환율 손익을 분리**해 산출
- **관심종목 차트** — lightweight-charts 기반 분할 차트, 코스피 지수 인디케이터
- **휴장일 처리** — 국내(KIS 휴장일 조회)·미국(거래세션 staleness) 판별로 **가짜 봉 생성 차단**, 전일 마감 표시

## 🛠️ 기술 스택

| 구분 | 사용 기술 |
|------|-----------|
| Backend | PHP, Laravel 8, 순수 PHP Stream Socket(웹소켓) |
| Frontend | Vue 3, Vite, Tailwind CSS, daisyUI, lightweight-charts |
| Database | MariaDB |
| 외부 API | 한국투자증권(KIS), Yahoo Finance |

## 🧩 핵심 설계 포인트

- **시세 제공자 추상화** — 국내/해외 Quote Provider를 인터페이스로 분리해 데이터 소스 교체에 유연
- **실시간 채널** — REST 조회와 별도로 웹소켓 서버(`agent:serve`)를 두어 실시간 시세를 push
- **손익 분리 모델** — 평가손익을 주가요인·환율요인으로 분해해 원화 기준 실질 손익을 표현
- **장애 대비** — 무료/외부 API의 변경·차단에 대비한 graceful 예외 처리
- **조회 전용** — 주문·매매 호출 없이 모니터링 목적의 읽기 전용 프록시로 한정

## 🚀 실행 방법

```bash
# 1) 환경변수 (KIS 키 등은 .env 에만)
cp backend/.env.example backend/.env

# 2) 백엔드 API
cd backend && composer install && php artisan serve --port=8000

# 3) 웹소켓 에이전트 (별도 터미널)
php artisan agent:serve            # :8080

# 4) 프런트엔드 (별도 터미널)
cd frontend && npm install && npm run dev   # :5173
```

## 🔐 보안 메모

- 한국투자증권 API 키·계좌 정보 등 모든 비밀값은 `.env`로만 관리하며 저장소에 포함하지 않습니다.
- 본 프로젝트는 시세 **조회/모니터링 전용**이며, 매매·투자 자문 기능을 포함하지 않습니다.
