# trading_info — 주식 실시간 모니터링 · 원화 통합 포트폴리오 트래커

미국/국내 주식을 **한 화면에서 실시간 모니터링**하고, 보유 종목을 **원화로 통합 평가**(주가손익·환율손익 분리)하는 풀스택 포트폴리오 트래커입니다. PC 웹 + 모바일 앱(Capacitor) 지원.

> Laravel + Vue 3 풀스택 · 자체 구현 WebSocket 실시간 서버 · 외부 API 마이그레이션(KIS → 토스증권) 완주

⚠️ **조회·자문 전용** — 주문/매매·실현손익·투자 추천 기능은 만들지 않습니다(평가손익만, 면책 표기).

## 주요 기능

- **실시간 모니터링 그리드** — 관심·보유 종목 시세를 WebSocket으로 3초 주기 갱신 (새로고침 없음)
- **원화 통합 포트폴리오 평가** — 미국 주식을 환율 반영 원화로 환산, **주가 손익과 환율 손익을 분리** 표시
- **차트** — 분봉/일봉, 나스닥100 선물·코스피 지수, 휴장일 자동 판별(가짜 봉 생성 차단)
- **장 세션 인지** — 거래소 캘린더 기반으로 개장/마감/휴장을 판정해 UI·데이터 처리를 자동 전환
- **모바일** — 동일 코드베이스를 Capacitor로 앱 패키징(LAN 접속, 호스트 동적 바인딩)

## 아키텍처

```
Vue 3 SPA (Vite · Tailwind · lightweight-charts)
   │  REST (8000)                 │  WebSocket (8080, 3초 사이클)
   ▼                              ▼
Laravel 8 REST API      순수 PHP Stream Socket WS 서버 (php artisan agent:serve)
   └──────────┬───────────────────┘
              ▼
   토스증권 Open API (시세·차트·환율·종목 마스터)
   Yahoo Finance (지수 · 미국 차트 폴백)  +  MariaDB (보유·관심·가격이력)
```

## 기술적 하이라이트

- **WebSocket 서버 직접 구현** — 라이브러리 없이 순수 PHP Stream Socket으로 핸드셰이크·프레이밍·브로드캐스트를 구현. long-running 프로세스의 코드 반영·재시작 운영까지 문서화
- **외부 API 전면 마이그레이션** — 한국투자증권(KIS) API → 토스증권 Open API로 데이터 소스를 통째로 교체하면서 KIS 의존을 완전 제거. 종목 메타데이터는 DB에 넣지 않고 API+캐싱으로 처리해 스키마를 FK 중심축만 남김 (상세: [`docs/features/toss-api-migration/`](docs/features/toss-api-migration/))
- **다단 폴백 체인** — 시세 소스 장애 대비 토스 → Yahoo → 24h 캐시 순 폴백. 실패는 조용히 삼키지 않고 `source` 라벨로 데이터 출처를 정직하게 표기
- **미국장 세션 대응** — 프리마켓/정규장/애프터마켓 세션 분기, 정규장 기준가를 별도 소스로 보강해 등락률 왜곡 방지
- **테스트** — 시세 폴백·세션 판정 등 핵심 로직 PHPUnit 테스트, Pint(포맷) 게이트

## 기술 스택

| 구분 | 사용 기술 |
|------|-----------|
| Backend | PHP · Laravel 8 · MariaDB |
| Frontend | Vue 3 (Composition API) · Vite · Tailwind CSS v4 · daisyUI · lightweight-charts |
| 실시간 | 순수 PHP Stream Socket WebSocket 서버 |
| 외부 API | 토스증권 Open API · Yahoo Finance |
| 모바일 | Capacitor |

## 실행

| 구성 | 명령 |
|---|---|
| REST API | `cd backend && php artisan serve --port=8000` |
| WebSocket | `cd backend && php artisan agent:serve` (8080) |
| 프론트(HMR) | `cd frontend && npm run dev` (5173) |
| 원클릭 | `run_trading_info.vbs` |

환경설정(`backend/.env`, git 제외): `TOSS_CLIENT_ID`/`TOSS_CLIENT_SECRET`(토스증권 WTS에서 발급) · `DB_*`(MariaDB). API 키·비밀값은 코드/DB에 두지 않고 전부 환경변수로 관리합니다.

## 핵심 구조

- `backend/app/Services/Toss/` — 토스 API 게이트웨이(토큰·배치시세·차트·환율·종목마스터)
- `backend/app/Services/Quote/TossQuoteProvider.php` — 포트폴리오 평가가(국내·미국)
- `backend/app/Services/MarketSessionService.php` — 장 세션·거래일 판정(거래소 캘린더)
- `backend/app/Http/Controllers/StockController.php` — 시세·차트·지수 REST
- `frontend/src/components/StockChart.vue` — 차트 컴포넌트

## 개발 방식

이 프로젝트는 역할별 AI 에이전트 팀(기획·백엔드·프론트엔드·QA·리뷰·보안)을 직접 구성·운영하는 [AI Agent Workspace](https://github.com/muhwa91/ai-agent-workspace) 거버넌스 아래에서 개발·유지보수됩니다 — API 계약 동결 후 병렬 구현(Contract-First), 훅 기반 품질 게이트, 비공개 모노레포 → 공개 미러 워크플로우.
