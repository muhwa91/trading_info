/**
 * 공유 포맷·색상 헬퍼 모음.
 *
 * PortfolioSummaryBar.vue, HoldingsPanel.vue 에서 글자 단위로 동일하게
 * 중복되던 함수를 한 곳으로 모은 것. 순수 함수만 포함.
 *
 * 색상 규칙 (국내·미국 구분 없이 통일 — 시맨틱 신호색):
 *   상승(증가) → up   (#F6465D, KR 적)
 *   하락(감소) → down (#3EA6FF, KR 청)
 */

// ── 금액 포맷 ──────────────────────────────────────────────────────

/**
 * 원화 현재가·평가금액을 "1,234,567원" 형태로.
 * null/undefined → '—'
 */
export function formatWon(value) {
  if (value === null || value === undefined) return '—';
  return `${Math.round(Number(value)).toLocaleString()}원`;
}

/**
 * 원화 손익을 "+1,234원" / "-1,234원" 형태로.
 * null/undefined → '—'
 */
export function formatProfitWon(value) {
  if (value === null || value === undefined) return '—';
  const n = Number(value);
  const sign = n >= 0 ? '+' : '-';
  return `${sign}${Math.round(Math.abs(n)).toLocaleString()}원`;
}

/**
 * 달러 손익을 "+1.23$" / "-1.23$" 형태로 (원화처럼 기호를 뒤에).
 * null/undefined → '—'
 */
export function formatProfitUSD(value) {
  if (value === null || value === undefined) return '—';
  const n = Number(value);
  const sign = n >= 0 ? '+' : '-';
  return `${sign}${Math.abs(n).toFixed(2)}$`;
}

/**
 * 손익률(소수, 예: 0.0123)을 "+1.23%" 형태로.
 * null/undefined → '—'
 * 비율 × 100 처리를 내부에서 수행.
 */
export function formatProfitRate(value) {
  if (value === null || value === undefined) return '—';
  const n = Number(value) * 100;
  const sign = n >= 0 ? '+' : '';
  return `${sign}${n.toFixed(2)}%`;
}

/**
 * 수량을 정수 형태로 (소수점 절사).
 * null/undefined → '—'
 */
export function formatQuantity(value) {
  if (value === null || value === undefined) return '—';
  const n = Number(value);
  return Math.round(n).toLocaleString();
}

/**
 * currency 에 따라 현재가·평단가를 포맷.
 *   currency === 'KRW' → "1,234,567원"
 *   그 외              → "1.23$" (기호를 뒤에)
 * null/undefined → '—'
 */
export function formatPrice(currency, value) {
  if (value === null || value === undefined) return '—';
  if (currency === 'KRW') {
    return `${Math.round(Number(value)).toLocaleString()}원`;
  }
  return `${Number(value).toFixed(2)}$`;
}

// ── 숫자/단위 분리 (compact 헤더 서브그리드 세로 정렬용) ────────────────
// 숫자 열(우측정렬 tabular)과 단위 열(좌측정렬 고정폭)을 별도 그리드 트랙에
// (2026-07-15: 8열 소수점 분리 시도 → 롤백. 숫자 전체 우측정렬 + 단위 별도 열 유지.)
// 넣기 위해 { num, unit } 로 분리 반환. → 단위(원/$/원)의 좌측 edge가 한 열에
// 세로 정렬되고 숫자는 우측 정렬돼 자릿수가 맞는다(02-계약 §D·§K).
// 겹침은 컨테이너 w-max·whitespace-nowrap·부모 overflow-x-auto 로 구조적 차단.
// null/undefined → { num: '—', unit: '' } (단위 없이 대시만).

/** 값(부호 없음)·달러: "15,924.00" + "$" */
export function usdParts(value) {
  if (value === null || value === undefined) return { num: '—', unit: '' };
  return {
    num: Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
    unit: '$',
  };
}

/** 값(부호 없음)·원화: "3,638,800" + "원" */
export function wonParts(value) {
  if (value === null || value === undefined) return { num: '—', unit: '' };
  return { num: Math.round(Number(value)).toLocaleString(), unit: '원' };
}

/** 손익(부호 항상)·달러: "-2308.00" + "$" */
export function profitUsdParts(value) {
  if (value === null || value === undefined) return { num: '—', unit: '' };
  const n = Number(value);
  return { num: `${n >= 0 ? '+' : '-'}${Math.abs(n).toFixed(2)}`, unit: '$' };
}

/** 손익(부호 항상)·원화: "-1,166,000" + "원" */
export function profitWonParts(value) {
  if (value === null || value === undefined) return { num: '—', unit: '' };
  const n = Number(value);
  return { num: `${n >= 0 ? '+' : '-'}${Math.round(Math.abs(n)).toLocaleString()}`, unit: '원' };
}

// ── 시각 포맷 ──────────────────────────────────────────────────────

/**
 * ISO 날짜 문자열을 "HH:MM 기준" 형태로.
 * 빈 값 → ''
 */
export function formatRecordedAt(val) {
  if (!val) return '';
  try {
    const d = new Date(val);
    const h = String(d.getHours()).padStart(2, '0');
    const m = String(d.getMinutes()).padStart(2, '0');
    return `${h}:${m} 기준`;
  } catch {
    return '';
  }
}

// ── 색상 헬퍼 ──────────────────────────────────────────────────────

/**
 * 손익값에 따른 텍스트 색 클래스 반환.
 * 국내·미국 구분 없이 상승=빨강(up), 하락=파랑(down)으로 통일.
 * @param {number|null} value  손익 금액(양수=이익, 음수=손실)
 * @param {'kr'|'us'}  market  호환을 위해 시그니처 유지 (무시됨)
 * @returns {string} Tailwind 색상 클래스
 */
export function profitColorClass(value, market) {
  const n = Number(value);
  if (isNaN(n)) return 'text-base-content/60';
  return n >= 0 ? 'text-up' : 'text-down';
}

// ── displayName 헬퍼 ───────────────────────────────────────────────

/**
 * HoldingsPanel 보유 종목 행의 표시명.
 * US 종목은 SEARCHABLE_STOCKS 에서 한글명 역조회 우선, 없으면 item.name → symbol.
 * KR 종목은 item.name → symbol.
 *
 * @param {object} item     보유 종목 객체 { symbol, market, name, ... }
 * @param {Array}  stocks   SEARCHABLE_STOCKS 배열 (stocksKnown.js 에서 import해 전달)
 * @returns {string}
 */
export function displayName(item, stocks) {
  if (item && item.market === 'US' && Array.isArray(stocks)) {
    const sym = String(item.symbol || '').toUpperCase();
    const known = stocks.find(s => String(s.ticker).toUpperCase() === sym);
    if (known && known.koName) return known.koName;
  }
  return (item && (item.name || item.symbol)) || '';
}
