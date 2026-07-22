/**
 * StockChart 로직 단위 테스트
 *
 * 커버:
 *   - changeTimeframe: 같은 값이면 상태 변경/emit 없음 (중복 호출 방어)
 *   - changeTimeframe: 다른 값이면 selectedTimeframe 갱신 + emit 실행
 *   - select @change 핸들러 패턴: $event.target.value 를 changeTimeframe에 전달
 *   - timeframes 상수: 7개 타임프레임, value 형식 검증
 *
 * 참고: StockChart.vue는 lightweight-charts(캔버스 DOM 의존)·ResizeObserver·
 * localStorage 등을 사용하므로 @vue/test-utils 마운트 없이
 * 내부 함수 로직을 독립 함수로 추출해 순수 단위 검증한다.
 */

import { describe, it, expect, vi } from 'vitest';
import { usExtHeadlineLabel } from '../utils/sessionBadge.js';

// ── 타임프레임 상수 (StockChart.vue 와 동일) ─────────────────────
const timeframes = [
  { label: '1분', value: '1m' },
  { label: '3분', value: '3m' },
  { label: '5분', value: '5m' },
  { label: '10분', value: '10m' },
  { label: '30분', value: '30m' },
  { label: '1시', value: '1h' },
  { label: '일봉', value: '1d' }
];

// ── changeTimeframe 로직 팩토리 ────────────────────────────────────
// StockChart.vue 의 changeTimeframe 함수와 동일한 로직을 독립 함수로 복제.
// 컴포넌트 마운트 없이도 핵심 분기를 검증할 수 있다.
function makeChangeTimeframe(initialTimeframe) {
  let selectedTimeframe = initialTimeframe;
  let shouldFitContent = false;
  const emitted = [];

  function changeTimeframe(value) {
    if (selectedTimeframe === value) return;
    selectedTimeframe = value;
    shouldFitContent = true;
    emitted.push(value);  // emit('timeframe-change', value) 대신
  }

  return {
    changeTimeframe,
    getSelectedTimeframe: () => selectedTimeframe,
    getShouldFitContent: () => shouldFitContent,
    getEmitted: () => emitted,
  };
}

// ── timeframes 상수 ────────────────────────────────────────────────

describe('timeframes 상수', () => {
  it('7개 타임프레임이 정의돼야 한다', () => {
    expect(timeframes).toHaveLength(7);
  });

  it('각 항목에 label과 value가 있어야 한다', () => {
    for (const tf of timeframes) {
      expect(tf).toHaveProperty('label');
      expect(tf).toHaveProperty('value');
      expect(typeof tf.label).toBe('string');
      expect(typeof tf.value).toBe('string');
    }
  });

  it('value 값 목록이 일치해야 한다', () => {
    const values = timeframes.map(tf => tf.value);
    expect(values).toEqual(['1m', '3m', '5m', '10m', '30m', '1h', '1d']);
  });

  it('기본값 3m 이 목록에 존재해야 한다', () => {
    expect(timeframes.some(tf => tf.value === '3m')).toBe(true);
  });
});

// ── changeTimeframe 로직 ───────────────────────────────────────────

describe('changeTimeframe — 같은 값 호출 (중복 방어)', () => {
  it('현재와 같은 값이면 selectedTimeframe 이 바뀌지 않는다', () => {
    const { changeTimeframe, getSelectedTimeframe } = makeChangeTimeframe('3m');
    changeTimeframe('3m');
    expect(getSelectedTimeframe()).toBe('3m');
  });

  it('현재와 같은 값이면 emit 이 발생하지 않는다', () => {
    const { changeTimeframe, getEmitted } = makeChangeTimeframe('3m');
    changeTimeframe('3m');
    expect(getEmitted()).toHaveLength(0);
  });

  it('현재와 같은 값이면 shouldFitContent 가 true 로 바뀌지 않는다', () => {
    const { changeTimeframe, getShouldFitContent } = makeChangeTimeframe('1d');
    changeTimeframe('1d');
    expect(getShouldFitContent()).toBe(false);
  });
});

describe('changeTimeframe — 다른 값 호출 (정상 전환)', () => {
  it('다른 값이면 selectedTimeframe 이 갱신된다', () => {
    const { changeTimeframe, getSelectedTimeframe } = makeChangeTimeframe('3m');
    changeTimeframe('5m');
    expect(getSelectedTimeframe()).toBe('5m');
  });

  it('다른 값이면 timeframe-change emit 이 발생한다', () => {
    const { changeTimeframe, getEmitted } = makeChangeTimeframe('3m');
    changeTimeframe('1h');
    expect(getEmitted()).toEqual(['1h']);
  });

  it('다른 값이면 shouldFitContent 가 true 로 설정된다', () => {
    const { changeTimeframe, getShouldFitContent } = makeChangeTimeframe('3m');
    changeTimeframe('1d');
    expect(getShouldFitContent()).toBe(true);
  });

  it('연속 두 번 다른 값 호출 시 마지막 값으로 확정된다', () => {
    const { changeTimeframe, getSelectedTimeframe, getEmitted } = makeChangeTimeframe('3m');
    changeTimeframe('5m');
    changeTimeframe('1d');
    expect(getSelectedTimeframe()).toBe('1d');
    expect(getEmitted()).toEqual(['5m', '1d']);
  });
});

describe('changeTimeframe — 모든 타임프레임 값 순환', () => {
  it('timeframes 의 각 value 로 전환할 수 있어야 한다', () => {
    const { changeTimeframe, getSelectedTimeframe } = makeChangeTimeframe('__init__');
    for (const tf of timeframes) {
      changeTimeframe(tf.value);
      expect(getSelectedTimeframe()).toBe(tf.value);
    }
  });
});

// ── select @change 핸들러 패턴 검증 ───────────────────────────────
// 템플릿: @change.stop="changeTimeframe($event.target.value)"
// select 에서 value 문자열이 정확히 changeTimeframe 에 전달되는지 확인

describe('select change 이벤트 → changeTimeframe 연동', () => {
  it('$event.target.value 문자열이 changeTimeframe 에 그대로 전달된다', () => {
    const { changeTimeframe, getSelectedTimeframe, getEmitted } = makeChangeTimeframe('3m');

    // 브라우저에서 select @change 이벤트가 발생하면:
    // @change.stop="changeTimeframe($event.target.value)"
    // $event.target.value 는 항상 문자열임을 검증
    const mockEvent = { target: { value: '10m' } };
    changeTimeframe(mockEvent.target.value);

    expect(getSelectedTimeframe()).toBe('10m');
    expect(getEmitted()).toEqual(['10m']);
  });

  it('select value 가 기존과 같으면 중복 emit 없음', () => {
    const { changeTimeframe, getEmitted } = makeChangeTimeframe('5m');

    const mockEvent = { target: { value: '5m' } };
    changeTimeframe(mockEvent.target.value);

    expect(getEmitted()).toHaveLength(0);
  });

  it('timeframes 의 모든 value 가 select option 값으로 유효하다', () => {
    // select option :value="tf.value" 로 바인딩되므로
    // changeTimeframe 에 전달될 수 있는 값이 전부 타임프레임 목록에 있어야 함
    const validValues = new Set(timeframes.map(tf => tf.value));
    for (const tf of timeframes) {
      expect(validValues.has(tf.value)).toBe(true);
    }
  });
});

// ── updateLastCandleDirectly 로직 단위 테스트 ───────────────────────────────
// StockChart.vue 의 updateLastCandleDirectly 핵심 연산을 독립 함수로 추출해 검증한다.
// (lightweight-charts 인스턴스 없이 캔들 봉 계산 로직만 순수 검증)

function computeCandleUpdate(lastCandle, price) {
  const effectivePrice = (price !== null && price !== undefined) ? price : lastCandle.close;
  let high = lastCandle.high;
  let low = lastCandle.low;
  if (effectivePrice > high) high = effectivePrice;
  if (effectivePrice < low) low = effectivePrice;
  return {
    time: lastCandle.time,
    open: lastCandle.open,
    high,
    low,
    close: effectivePrice,
  };
}

describe('updateLastCandleDirectly — 봉 갱신 연산', () => {
  const baseCandle = { time: 1700000000, open: 100, high: 110, low: 90, close: 105, volume: 1000 };

  it('가격이 high 보다 높으면 high 를 갱신한다', () => {
    const result = computeCandleUpdate(baseCandle, 115);
    expect(result.high).toBe(115);
    expect(result.close).toBe(115);
    expect(result.low).toBe(90);
    expect(result.open).toBe(100);
  });

  it('가격이 low 보다 낮으면 low 를 갱신한다', () => {
    const result = computeCandleUpdate(baseCandle, 85);
    expect(result.low).toBe(85);
    expect(result.close).toBe(85);
    expect(result.high).toBe(110);
  });

  it('가격이 high-low 범위 안이면 high·low 는 그대로 유지된다', () => {
    const result = computeCandleUpdate(baseCandle, 103);
    expect(result.high).toBe(110);
    expect(result.low).toBe(90);
    expect(result.close).toBe(103);
  });

  it('가격이 현재 close 와 동일해도 봉 데이터를 반환한다 (중복 틱 방어)', () => {
    const result = computeCandleUpdate(baseCandle, 105);
    expect(result.close).toBe(105);
    expect(result.high).toBe(110);
    expect(result.low).toBe(90);
  });

  it('price 가 null 이면 lastCandle.close 를 폴백으로 사용한다', () => {
    const result = computeCandleUpdate(baseCandle, null);
    expect(result.close).toBe(baseCandle.close);
  });

  it('price 가 undefined 이면 lastCandle.close 를 폴백으로 사용한다', () => {
    const result = computeCandleUpdate(baseCandle, undefined);
    expect(result.close).toBe(baseCandle.close);
  });

  it('time 과 open 은 항상 lastCandle 값을 유지한다', () => {
    const result = computeCandleUpdate(baseCandle, 107);
    expect(result.time).toBe(baseCandle.time);
    expect(result.open).toBe(baseCandle.open);
  });
});

// ── lastTrackedPrice 기반 동일값 중복 틱 방어 로직 ──────────────────────────
// candles watch 에서 price === lastTrackedPrice 일 때 updateLastCandleDirectly 를
// 재호출하는 분기를 독립적으로 검증한다.

function makePriceTracker() {
  let lastTrackedPrice = null;
  const updateCalls = [];

  function updateLastCandleDirectly(price) {
    // 실제 차트 업데이트 대신 호출 기록
    const effectivePrice = (price !== null && price !== undefined) ? price : null;
    if (effectivePrice !== null) {
      updateCalls.push(effectivePrice);
      lastTrackedPrice = effectivePrice;
    }
  }

  function onCandlesUpdate(currentPrice) {
    // candles watch 에서 동일값 중복 틱 방어 분기
    if (currentPrice !== null && currentPrice === lastTrackedPrice) {
      updateLastCandleDirectly(currentPrice);
    }
  }

  function onCurrentPriceChange(newPrice) {
    // currentPrice watch 에서 봉 갱신
    if (newPrice !== null) {
      updateLastCandleDirectly(newPrice);
    }
  }

  return {
    onCandlesUpdate,
    onCurrentPriceChange,
    getUpdateCalls: () => updateCalls,
    getLastTrackedPrice: () => lastTrackedPrice,
    reset: () => { lastTrackedPrice = null; },
  };
}

describe('동일값 중복 틱 방어 — candles watch 분기', () => {
  it('currentPrice 가 처음 수신되면 lastTrackedPrice 에 기록된다', () => {
    const tracker = makePriceTracker();
    tracker.onCurrentPriceChange(100);
    expect(tracker.getLastTrackedPrice()).toBe(100);
    expect(tracker.getUpdateCalls()).toEqual([100]);
  });

  it('candles 갱신 시 currentPrice 가 lastTrackedPrice 와 같으면 재갱신한다', () => {
    const tracker = makePriceTracker();
    tracker.onCurrentPriceChange(100); // 첫 틱 → tracked = 100
    tracker.onCandlesUpdate(100);       // candles 갱신 + price 동일 → 재갱신
    expect(tracker.getUpdateCalls()).toEqual([100, 100]);
  });

  it('currentPrice 가 바뀌면 lastTrackedPrice 도 바뀐다', () => {
    const tracker = makePriceTracker();
    tracker.onCurrentPriceChange(100);
    tracker.onCurrentPriceChange(102);
    expect(tracker.getLastTrackedPrice()).toBe(102);
  });

  it('candles 갱신 시 currentPrice 가 lastTrackedPrice 와 다르면 재갱신하지 않는다', () => {
    const tracker = makePriceTracker();
    tracker.onCurrentPriceChange(100); // tracked = 100
    tracker.onCandlesUpdate(102);       // price 다름 → Vue watch(currentPrice)가 처리할 것
    expect(tracker.getUpdateCalls()).toEqual([100]); // 재갱신 없음
  });

  it('reset 후에는 lastTrackedPrice 가 null 이다 (종목·타임프레임 전환)', () => {
    const tracker = makePriceTracker();
    tracker.onCurrentPriceChange(100);
    tracker.reset();
    expect(tracker.getLastTrackedPrice()).toBeNull();
  });

  it('currentPrice 가 null 이면 candles watch 에서 재갱신하지 않는다', () => {
    const tracker = makePriceTracker();
    tracker.onCandlesUpdate(null);
    expect(tracker.getUpdateCalls()).toHaveLength(0);
  });
});

// ── US 연장세션 정규장 등락률 2줄 표시 게이트 ─────────────────────────────
// StockChart.vue 의 showUSExtChange / formattedRegularChangePercent 로직을 독립 복제.
// (isKorean·isIndex·regularChangePercent·usSession 분기만 순수 검증)

function showUSExtChange({ isKorean, isIndex, regularChangePercent, usSession }) {
  if (isKorean || isIndex) return false;
  if (regularChangePercent === null) return false;
  return ['PRE', 'AFT', 'EXT_NIGHT'].includes(usSession);
}

function formattedRegularChangePercent(regularChangePercent) {
  if (regularChangePercent === null) return null;
  const sign = regularChangePercent >= 0 ? '+' : '';
  return `${sign}${regularChangePercent.toFixed(2)}%`;
}

describe('showUSExtChange — 정규장 보조 줄 게이트', () => {
  const usExt = { isKorean: false, isIndex: false, regularChangePercent: -2.1, usSession: 'AFT' };

  it('US 연장세션(AFT/PRE/EXT_NIGHT) + 정규장값 있으면 2줄을 켠다', () => {
    for (const s of ['PRE', 'AFT', 'EXT_NIGHT']) {
      expect(showUSExtChange({ ...usExt, usSession: s })).toBe(true);
    }
  });

  it('정규장(REGULAR)·장마감(CLOSED)이면 1줄 유지', () => {
    expect(showUSExtChange({ ...usExt, usSession: 'REGULAR' })).toBe(false);
    expect(showUSExtChange({ ...usExt, usSession: 'CLOSED' })).toBe(false);
  });

  it('KR 종목·지수는 세션 무관 1줄 유지', () => {
    expect(showUSExtChange({ ...usExt, isKorean: true })).toBe(false);
    expect(showUSExtChange({ ...usExt, isIndex: true })).toBe(false);
  });

  it('regular_change_percent 부재(null)면 1줄 유지 (회귀 방지)', () => {
    expect(showUSExtChange({ ...usExt, regularChangePercent: null })).toBe(false);
  });
});

describe('formattedRegularChangePercent — 정규장 등락률 포맷', () => {
  it('양수엔 + 부호, 소수 2자리', () => {
    expect(formattedRegularChangePercent(1.5)).toBe('+1.50%');
    expect(formattedRegularChangePercent(0)).toBe('+0.00%');
  });

  it('음수는 부호 없이 - 그대로', () => {
    expect(formattedRegularChangePercent(-8.42)).toBe('-8.42%');
  });

  it('null 이면 null 반환', () => {
    expect(formattedRegularChangePercent(null)).toBeNull();
  });
});

// ── US 연장세션 헤드라인 세션 라벨(토스식 "애프터마켓에서") ────────────────────
// sessionBadge.usExtHeadlineLabel(원본) + StockChart 의 usSessionHeadlineLabel 게이트(KR·지수 제외)를 검증.

// StockChart.vue usSessionHeadlineLabel computed 와 동일한 게이트 복제.
function headlineLabel({ isKorean, isIndex, usSession, regularChangePercent = null }) {
  if (isKorean || isIndex) return '';
  // AFT/EXT_NIGHT: regularChangePercent non-null(=당일 종가 기준)일 때만 라벨. PRE는 예외로 항상 라벨.
  if (usSession !== 'PRE' && regularChangePercent === null) return '';
  return usExtHeadlineLabel(usSession);
}

describe('usExtHeadlineLabel — 세션 → 헤드라인 라벨 매핑', () => {
  it('AFT → "애프터마켓에서"', () => {
    expect(usExtHeadlineLabel('AFT')).toBe('애프터마켓에서');
  });
  it('PRE → "프리마켓에서"', () => {
    expect(usExtHeadlineLabel('PRE')).toBe('프리마켓에서');
  });
  it('EXT_NIGHT → "주간거래에서"', () => {
    expect(usExtHeadlineLabel('EXT_NIGHT')).toBe('주간거래에서');
  });
  it('정규장(REGULAR)·장마감(CLOSED)·빈값은 라벨 없음', () => {
    expect(usExtHeadlineLabel('REGULAR')).toBe('');
    expect(usExtHeadlineLabel('CLOSED')).toBe('');
    expect(usExtHeadlineLabel('')).toBe('');
  });
});

describe('usSessionHeadlineLabel — 헤드라인 라벨 노출 게이트', () => {
  // 당일 종가 기준일 때(regularChangePercent non-null) = 순수 애프터 → 라벨 노출.
  const usAft = { isKorean: false, isIndex: false, usSession: 'AFT', regularChangePercent: -1.17 };

  it('US 연장세션(AFT) + 당일 종가 기준(regularChangePercent non-null)이면 "애프터마켓에서" 노출', () => {
    expect(headlineLabel(usAft)).toBe('애프터마켓에서');
  });

  it('AFT + regularChangePercent=null(regular_close cold 폴백)이면 라벨 숨김 — 라벨↔숫자 불일치 방지', () => {
    expect(headlineLabel({ ...usAft, regularChangePercent: null })).toBe('');
  });

  it('EXT_NIGHT도 regularChangePercent=null이면 라벨 숨김', () => {
    expect(headlineLabel({ ...usAft, usSession: 'EXT_NIGHT', regularChangePercent: null })).toBe('');
  });

  it('PRE는 예외 — regularChangePercent=null(base=prevRegular 정상)이어도 "프리마켓에서" 유지', () => {
    expect(headlineLabel({ ...usAft, usSession: 'PRE', regularChangePercent: null })).toBe('프리마켓에서');
  });

  it('US 정규장(REGULAR)이면 라벨 없음 (헤드라인 1줄 유지, 회귀 방지)', () => {
    expect(headlineLabel({ ...usAft, usSession: 'REGULAR' })).toBe('');
  });

  it('KR 종목·지수는 연장세션 코드여도 라벨 없음', () => {
    expect(headlineLabel({ ...usAft, isKorean: true })).toBe('');
    expect(headlineLabel({ ...usAft, isIndex: true })).toBe('');
  });
});
