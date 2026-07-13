/**
 * App.vue 지수 헤더 인라인 시세 로직 단위 테스트.
 *
 * App.vue 의 isNqFuturesTrading 시간 경계 판정과 틱 플래시(triggerIndexFlash)
 * 가드/방향 로직을 인라인 복제해 컴포넌트 마운트 없이 순수 검증한다.
 * (StockChart.test.js·gridColsMap.test.js 와 동일한 하우스 스타일 — @vue/test-utils 미사용.)
 *
 * 커버:
 *   - isNqFuturesTrading: session 필드 우선 / KST 폴백 휴장창(토 06시~월 07시) 경계 + NQ null 가드
 *   - triggerIndexFlash: null/동일가 → 무점등, 상승→up, 하락→down
 */

import { describe, it, expect } from 'vitest';

// ── isNqFuturesTrading 판정 로직 복제 (App.vue 와 동일) ─────────────────
// 원본: nq 존재·가격 유효 → session 필드 있으면 신뢰, 없으면 KST 시계 폴백.
// 폴백 휴장창은 백엔드 StockController NQ=F 와 동일: 토 06:00~월 07:00.
// 테스트 결정성을 위해 (nq, kstDate) 를 주입받는 순수형으로 복제한다.
function isNqFuturesTrading(nq, kstDate) {
  if (!nq || nq.current_price === null || nq.current_price === undefined) return false;
  if (nq.session) return nq.session === '거래중';
  const dow = kstDate.getDay(); // 0=일 … 6=토
  const t = kstDate.getHours() * 100 + kstDate.getMinutes();
  const isClosed = (dow === 6 && t >= 600) || dow === 0 || (dow === 1 && t < 700);
  return !isClosed;
}

// 요일 헬퍼: 2026-07-13 = 월요일 기준으로 오프셋
function kstAt(dowOffset, hh, mm) {
  const d = new Date(2026, 6, 13 + dowOffset, hh, mm, 0);
  return d;
}
const NQ = { current_price: 23000 };

describe('isNqFuturesTrading — 선물 거래시간 게이트', () => {
  it('session="거래중" → 표시(필드 우선)', () => {
    expect(isNqFuturesTrading({ current_price: 23000, session: '거래중' }, kstAt(5, 12, 0))).toBe(true);
  });

  it('session="장마감" → 소멸(필드 우선, 시계 무시)', () => {
    expect(isNqFuturesTrading({ current_price: 23000, session: '장마감' }, kstAt(0, 12, 0))).toBe(false);
  });

  it('금요일 낮(폴백) → 거래중', () => {
    expect(isNqFuturesTrading(NQ, kstAt(4, 14, 0))).toBe(true); // 월+4=금
  });

  it('토요일 05:59(폴백) → 거래중(마감 직전)', () => {
    expect(isNqFuturesTrading(NQ, kstAt(5, 5, 59))).toBe(true); // 월+5=토
  });

  it('토요일 06:00(폴백) → 소멸(휴장 시작 경계)', () => {
    expect(isNqFuturesTrading(NQ, kstAt(5, 6, 0))).toBe(false); // 월+5=토
  });

  it('일요일(폴백) → 소멸(휴장)', () => {
    expect(isNqFuturesTrading(NQ, kstAt(6, 12, 0))).toBe(false); // 월+6=일
  });

  it('월요일 06:59(폴백) → 소멸(재개 직전)', () => {
    expect(isNqFuturesTrading(NQ, kstAt(0, 6, 59))).toBe(false);
  });

  it('월요일 07:00(폴백) → 거래중(재개 경계)', () => {
    expect(isNqFuturesTrading(NQ, kstAt(0, 7, 0))).toBe(true);
  });

  it('NQ 데이터 없음 → 소멸(가드)', () => {
    expect(isNqFuturesTrading(null, kstAt(0, 12, 0))).toBe(false);
  });

  it('NQ current_price null → 소멸(가드)', () => {
    expect(isNqFuturesTrading({ current_price: null, session: '거래중' }, kstAt(0, 12, 0))).toBe(false);
  });

  it('NQ current_price undefined → 소멸(가드)', () => {
    expect(isNqFuturesTrading({}, kstAt(0, 12, 0))).toBe(false);
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
