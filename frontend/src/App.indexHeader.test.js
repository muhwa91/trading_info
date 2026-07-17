/**
 * App.vue 지수 헤더 인라인 시세 로직 단위 테스트.
 *
 * App.vue 의 isNqFuturesTrading 시간 경계 판정과 틱 플래시(triggerIndexFlash)
 * 가드/방향 로직을 컴포넌트 마운트 없이 순수 검증한다.
 * (StockChart.test.js·gridColsMap.test.js 와 동일한 하우스 스타일 — @vue/test-utils 미사용.)
 *
 * 커버:
 *   - isNqFuturesTrading: session 필드 우선 / ET 폴백 휴장창(CME 실경계) + NQ null 가드
 *   - triggerIndexFlash: null/동일가 → 무점등, 상승→up, 하락→down
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { isNqTradingByEtClock } from './utils/nqSession.js';

// ─────────────────────────────────────────────────────────────────────────
// App.vue 의 isNqFuturesTrading computed 재현 — 단, **폴백은 복제하지 않는다.**
//
//   ET 휴장창 판정(=이 버그의 진원지인 DST 환산)은 utils/nqSession.js 로 추출됐고
//   아래에서 **실제 import** 로 태운다. 모듈이 옛 KST 하드코딩으로 회귀하면
//   아래 ET/EST 표가 구조적으로 FAIL 한다(더 이상 "복제본만 지키는" 테스트가 아니다).
//
//   ⚠️ 남은 한계 — 감싸는 두 줄(NQ null 가드 · nq.session 필드 우선)은 App.vue 의
//   <script setup> 안 computed 라 import 가 불가능해 계약대로 여기 옮겨 적었다.
//   즉 **그 두 줄이 App.vue 에서 회귀하면 이 테스트는 침묵한다.** 그 경로까지 잡으려면
//   컴포넌트 마운트(@vue/test-utils)가 필요한데 이 레포 하우스 스타일 밖이다.
//   지켜지는 범위: 폴백 = 실제 프로덕션 코드 / 가드·필드우선 = 계약(사람이 눈으로 동기화).
//
//   시각은 주입하지 않는다 — nqSession.js 가 인자를 받지 않는 것과 같은 이유다.
//   KST/ET 오프셋을 손으로 계산해 주입하면 정작 틀렸던 **DST 환산 자체**를 테스트가
//   건너뛴다. vi.setSystemTime 으로 실클럭만 고정해 런타임 Intl tz 데이터로 환산을 태운다.
// ─────────────────────────────────────────────────────────────────────────
function isNqFuturesTrading(nq) {
  if (!nq || nq.current_price === null || nq.current_price === undefined) return false;
  if (nq.session) return nq.session === '거래중';
  return isNqTradingByEtClock(); // ← 실제 프로덕션 모듈 (복제 아님)
}

const NQ = { current_price: 23000 };

/** 주어진 UTC 순간으로 시스템 클럭을 고정하고 판정을 얻는다. */
function tradingAt(utcInstant, nq = NQ) {
  vi.useFakeTimers();
  vi.setSystemTime(new Date(utcInstant));
  return isNqFuturesTrading(nq);
}

afterEach(() => {
  vi.useRealTimers();
});

describe('isNqFuturesTrading — session 필드 우선 / NQ 가드', () => {
  it('session="거래중" → 표시(필드 우선, 시계 무시)', () => {
    // 토요일(휴장창 한복판)이어도 백엔드 session 이 이기는지
    expect(tradingAt('2026-07-18T16:00:00Z', { current_price: 23000, session: '거래중' })).toBe(true);
  });

  it('session="장마감" → 소멸(필드 우선, 시계 무시)', () => {
    // 평일 낮(거래중 시간)이어도 백엔드 session 이 이기는지
    expect(tradingAt('2026-07-21T16:00:00Z', { current_price: 23000, session: '장마감' })).toBe(false);
  });

  it('NQ 데이터 없음 → 소멸(가드)', () => {
    expect(tradingAt('2026-07-21T16:00:00Z', null)).toBe(false);
  });

  it('NQ current_price null → 소멸(가드)', () => {
    expect(tradingAt('2026-07-21T16:00:00Z', { current_price: null, session: '거래중' })).toBe(false);
  });

  it('NQ current_price undefined → 소멸(가드)', () => {
    expect(tradingAt('2026-07-21T16:00:00Z', {})).toBe(false);
  });
});

// ── ET 폴백 휴장창 — CME 실경계 ──────────────────────────────────────────
// 계약(동결): 금 17:00 ET 마감 → 일 18:00 ET 재개 = 주말 휴장 · 평일 17:00~18:00 ET = 유지보수.
//
// 옛 코드는 이 창을 KST 리터럴(토 06:00~월 07:00)로 굳혔다. 그 값은 **여름(EDT) 한정**이라
// 겨울(EST)엔 양 끝이 1시간씩 어긋난다(연 ~36h 오판). 아래 표의 kst 열이 그 차이를 보여준다 —
// EDT 행은 옛 코드와도 일치하고, **EST 행에서만 갈라진다.** EDT 만 검증하면 회귀를 놓친다.
//
// 입력은 UTC 순간 하나뿐이고, et/kst 열은 그 순간의 두 지역 벽시계다(둘을 한 표에 못박는다).
const ET_BOUNDARIES = [
  // ── 여름 EDT (ET = UTC-4) ────────────────────────────────────────────
  { utc: '2026-07-17T20:59:00Z', et: 'EDT 금 16:59', kst: '토 05:59', expected: true, why: '금 17:00 마감 직전' },
  { utc: '2026-07-17T21:01:00Z', et: 'EDT 금 17:01', kst: '토 06:01', expected: false, why: '금 17:00 마감 후' },
  { utc: '2026-07-18T16:00:00Z', et: 'EDT 토 12:00', kst: '일 01:00', expected: false, why: '토요일 종일 휴장' },
  { utc: '2026-07-19T21:59:00Z', et: 'EDT 일 17:59', kst: '월 06:59', expected: false, why: '일 18:00 재개 직전' },
  { utc: '2026-07-19T22:01:00Z', et: 'EDT 일 18:01', kst: '월 07:01', expected: true, why: '일 18:00 재개 후' },
  { utc: '2026-07-21T21:30:00Z', et: 'EDT 화 17:30', kst: '수 06:30', expected: false, why: '평일 유지보수(옛 코드엔 아예 없던 창)' },
  { utc: '2026-07-21T22:01:00Z', et: 'EDT 화 18:01', kst: '수 07:01', expected: true, why: '평일 유지보수 종료 후' },

  // ── 겨울 EST (ET = UTC-5) — 옛 KST 하드코딩이 틀렸던 체제 ────────────
  { utc: '2026-01-16T21:59:00Z', et: 'EST 금 16:59', kst: '토 06:59', expected: true, why: '금 17:00 마감 직전. 옛 코드는 토 06:00 부터 잘라 장마감으로 오판했다' },
  { utc: '2026-01-16T22:01:00Z', et: 'EST 금 17:01', kst: '토 07:01', expected: false, why: '금 17:00 마감 후' },
  { utc: '2026-01-17T17:00:00Z', et: 'EST 토 12:00', kst: '일 02:00', expected: false, why: '토요일 종일 휴장' },
  { utc: '2026-01-18T22:59:00Z', et: 'EST 일 17:59', kst: '월 07:59', expected: false, why: '일 18:00 재개 직전. 옛 코드는 월 07:00 부터 열어 거래중으로 오판했다' },
  { utc: '2026-01-18T23:01:00Z', et: 'EST 일 18:01', kst: '월 08:01', expected: true, why: '일 18:00 재개 후' },
  { utc: '2026-01-20T22:30:00Z', et: 'EST 화 17:30', kst: '수 07:30', expected: false, why: '평일 유지보수(옛 코드엔 아예 없던 창)' },
  { utc: '2026-01-20T23:01:00Z', et: 'EST 화 18:01', kst: '수 08:01', expected: true, why: '평일 유지보수 종료 후' },
];

describe('isNqFuturesTrading — ET 폴백 휴장창(CME 실경계)', () => {
  it.each(ET_BOUNDARIES)('$et ($kst KST) → $expected — $why', ({ utc, expected }) => {
    expect(tradingAt(utc)).toBe(expected);
  });
});

// ── EST 실환산 못박기 ───────────────────────────────────────────────────
// ★ 옛 코드가 틀렸던 바로 그 지점. EST 에서 실제 경계는:
//     금 17:00 ET = **토 07:00 KST** (옛 코드: 토 06:00)
//     일 18:00 ET = **월 08:00 KST** (옛 코드: 월 07:00)
//   → 옛 코드는 토 06:00~07:00 KST 를 산 채로 묻고, 월 07:00~08:00 KST 를 죽은 채로 열었다.
const EST_KST_PINS = [
  { utc: '2026-01-16T21:30:00Z', kst: '토 06:30', et: '금 16:30 EST', expected: true, why: '아직 거래중 — 옛 코드는 장마감으로 오판' },
  { utc: '2026-01-16T22:00:00Z', kst: '토 07:00', et: '금 17:00 EST', expected: false, why: 'EST 마감 경계는 토 06:00 이 아니라 토 07:00 KST' },
  { utc: '2026-01-18T22:30:00Z', kst: '월 07:30', et: '일 17:30 EST', expected: false, why: '아직 휴장 — 옛 코드는 거래중으로 오판' },
  { utc: '2026-01-18T23:00:00Z', kst: '월 08:00', et: '일 18:00 EST', expected: true, why: 'EST 재개 경계는 월 07:00 이 아니라 월 08:00 KST' },
];

describe('isNqFuturesTrading — EST 실환산 경계(겨울엔 KST 로 1시간씩 밀린다)', () => {
  it.each(EST_KST_PINS)('$kst KST = $et → $expected — $why', ({ utc, expected }) => {
    expect(tradingAt(utc)).toBe(expected);
  });
});

// ── headerInlineQuotes 선택 로직 복제 (App.vue 와 동일) ─────────────────
// 지수 헤더 인라인 시세에 어떤 지수가 뜨는지 결정.
// - 나스닥100·코스피 종합지수: 접힘일 때만(펼치면 차트 카드가 값을 보여줌).
// - 코스피 야간선물: 차트 카드가 없으므로 야간 세션이면 접힘/펼침 무관 항상 노출, 낮엔 미노출.
// data = 존재 티커 집합(스토어 데이터 유무).
function headerInlineQuotes(collapsed, nqTrading, kospiRegular, kospiNight, data) {
  const out = [];
  if (collapsed && nqTrading && data['NQ=F']) out.push('NQ=F');
  if (collapsed && kospiRegular && data['KOSPI200']) out.push('KOSPI200');
  else if (kospiNight && data['KOSPI_NIGHT']) out.push('KOSPI_NIGHT');
  return out;
}
const ALL = { 'NQ=F': {}, 'KOSPI200': {}, 'KOSPI_NIGHT': {} };

describe('headerInlineQuotes — 지수 헤더 인라인 노출 규칙', () => {
  it('야간 세션 + 펼침 → 야간선물이 헤더에 노출(차트 카드 없이)', () => {
    expect(headerInlineQuotes(false, false, false, true, ALL)).toEqual(['KOSPI_NIGHT']);
  });

  it('야간 세션 + 접힘 + NQ 거래중 → 나스닥100 + 야간선물', () => {
    expect(headerInlineQuotes(true, true, false, true, ALL)).toEqual(['NQ=F', 'KOSPI_NIGHT']);
  });

  it('정규장(낮) + 펼침 → 헤더 비움(야간선물 미노출, 코스피는 차트 카드로)', () => {
    expect(headerInlineQuotes(false, false, true, false, ALL)).toEqual([]);
  });

  it('정규장(낮) + 접힘 → 나스닥100 + 코스피 종합지수(야간선물 없음)', () => {
    expect(headerInlineQuotes(true, true, true, false, ALL)).toEqual(['NQ=F', 'KOSPI200']);
  });

  it('장마감/휴장(정규장·야간 모두 아님) → 헤더에 코스피류 없음', () => {
    expect(headerInlineQuotes(false, false, false, false, ALL)).toEqual([]);
    expect(headerInlineQuotes(true, false, false, false, ALL)).toEqual([]);
  });

  it('야간 세션이라도 데이터 없으면 미노출(가드)', () => {
    expect(headerInlineQuotes(false, false, false, true, { 'NQ=F': {}, 'KOSPI200': {} })).toEqual([]);
  });
});

// ── triggerIndexFlash 가드/방향 로직 복제 (App.vue 와 동일) ─────────────
// PortfolioSummaryBar.triggerFlash 도 동일 가드를 공유한다.
function flashDirection(oldP, newP) {
  if (oldP == null || newP == null || newP === oldP) return null; // 무점등
  return newP > oldP ? 'up' : 'down';
}

describe('triggerIndexFlash — 플래시 방향/가드', () => {
  it('상승 → up', () => {
    expect(flashDirection(100, 101)).toBe('up');
  });

  it('하락 → down', () => {
    expect(flashDirection(101, 100)).toBe('down');
  });

  it('동일가 → 무점등(null)', () => {
    expect(flashDirection(100, 100)).toBeNull();
  });

  it('이전값 null → 무점등(첫 수신 시 스퍼리어스 플래시 방지)', () => {
    expect(flashDirection(null, 100)).toBeNull();
  });

  it('새 값 null → 무점등', () => {
    expect(flashDirection(100, null)).toBeNull();
  });
});
