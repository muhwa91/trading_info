/**
 * App.vue 지수 헤더 인라인 시세 로직 단위 테스트.
 *
 * App.vue 의 isUsRegularSession 시간 경계 판정과 틱 플래시(triggerIndexFlash)
 * 가드/방향 로직을 인라인 복제해 컴포넌트 마운트 없이 순수 검증한다.
 * (StockChart.test.js·gridColsMap.test.js 와 동일한 하우스 스타일 — @vue/test-utils 미사용.)
 *
 * 커버:
 *   - isUsRegularSession: ET 09:30~16:00 경계(포함/제외) + 주말 제외 + NQ null 가드
 *   - triggerIndexFlash: null/동일가 → 무점등, 상승→up, 하락→down
 */

import { describe, it, expect } from 'vitest';

// ── isUsRegularSession 판정 로직 복제 (App.vue 와 동일) ─────────────────
// 원본: nq 존재·가격 유효 → ET 요일(월~금)·시각(0930~1600) 경계.
// 테스트 결정성을 위해 (nq, etDate) 를 주입받는 순수형으로 복제한다.
function isUsRegularSession(nq, etDate) {
  if (!nq || nq.current_price === null || nq.current_price === undefined) return false;
  const dow = etDate.getDay(); // 0=일 … 6=토
  const t = etDate.getHours() * 100 + etDate.getMinutes();
  return dow >= 1 && dow <= 5 && t >= 930 && t < 1600;
}

// 요일 헬퍼: 2026-07-13 = 월요일 기준으로 오프셋
function etAt(dowOffset, hh, mm) {
  const d = new Date(2026, 6, 13 + dowOffset, hh, mm, 0);
  return d;
}
const NQ = { current_price: 23000 };

describe('isUsRegularSession — ET 정규장 경계', () => {
  it('평일 09:30 정각 → 개장(포함 경계)', () => {
    expect(isUsRegularSession(NQ, etAt(0, 9, 30))).toBe(true);
  });

  it('평일 09:29 → 미개장(경계 직전)', () => {
    expect(isUsRegularSession(NQ, etAt(0, 9, 29))).toBe(false);
  });

  it('평일 15:59 → 개장(마감 직전)', () => {
    expect(isUsRegularSession(NQ, etAt(0, 15, 59))).toBe(true);
  });

  it('평일 16:00 정각 → 마감(제외 경계)', () => {
    expect(isUsRegularSession(NQ, etAt(0, 16, 0))).toBe(false);
  });

  it('평일 장중(12:00) → 개장', () => {
    expect(isUsRegularSession(NQ, etAt(0, 12, 0))).toBe(true);
  });

  it('토요일 장중 시간대 → 미개장(주말)', () => {
    expect(isUsRegularSession(NQ, etAt(5, 12, 0))).toBe(false); // 월+5=토
  });

  it('일요일 장중 시간대 → 미개장(주말)', () => {
    expect(isUsRegularSession(NQ, etAt(6, 12, 0))).toBe(false); // 월+6=일
  });

  it('NQ 데이터 없음 → 미개장(가드)', () => {
    expect(isUsRegularSession(null, etAt(0, 12, 0))).toBe(false);
  });

  it('NQ current_price null → 미개장(가드)', () => {
    expect(isUsRegularSession({ current_price: null }, etAt(0, 12, 0))).toBe(false);
  });

  it('NQ current_price undefined → 미개장(가드)', () => {
    expect(isUsRegularSession({}, etAt(0, 12, 0))).toBe(false);
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
