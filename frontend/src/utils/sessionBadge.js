/**
 * 세션 배지(정규장·프리마켓·애프터마켓·주간거래·장마감 …) 단일 소스.
 *
 * 크기 = DESIGN.md §5 배지 규격 (h-[22px] px-2 rounded-xs text-2xs font-medium tracking-wide).
 * 색은 3계층 시맨틱 토큰: 정규장=ses-open(앰버) / 연장=ses-ext(틸) / 마감·기타=중립 muted.
 *
 * 사용처별 레이아웃 요구(whitespace-nowrap·shrink-0·tracking 등)만 각자 덧붙인다.
 * 크기·색 규칙은 여기서만 바꾼다 — 클래스 문자열을 컴포넌트에 복제하지 말 것.
 */

export const SESSION_BADGE_BASE =
  'inline-flex items-center justify-center h-[22px] px-2 rounded-xs border text-2xs font-medium tracking-wide leading-tight';

const TONE_OPEN   = 'text-ses-open bg-ses-open-weak border-ses-open-line';
const TONE_EXT    = 'text-ses-ext bg-ses-ext-weak border-ses-ext-line';
const TONE_CLOSED = 'text-base-content/40 bg-base-200/40 border-base-content/10';

// 연장 세션 라벨 — 백엔드 session / indexQuoteLabel 이 내는 표기를 모두 수용
const EXT_LABELS = ['프리마켓', '애프터마켓', '주간거래', '야간거래', '거래중', '야간 거래중'];

/**
 * 세션 한글 라벨 → 색 토큰 클래스.
 * @param {string} label 예: '정규장' | '프리마켓' | '장마감' | '전일 마감'
 */
export function sessionBadgeTone(label) {
  if (label === '정규장') return TONE_OPEN;
  if (EXT_LABELS.includes(label)) return TONE_EXT;
  return TONE_CLOSED;
}
