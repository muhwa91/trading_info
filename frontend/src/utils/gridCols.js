/**
 * 4차트 그리드 열 클래스 — 사용자가 명시 선택한 열 수(1=세로 1열/차트 크게, 2=2×2)를 Tailwind 클래스로.
 * 폭 무관 항상 이 값으로 고정(반응형 자동 배치 없음). 4차트 모드는 2열(grid-cols-2 = repeat(2, minmax(0,1fr)))로
 * 트랙에 맞춰 카드가 절반씩 축소된다(카드 min-w 제거가 전제 — 있으면 트랙을 넘어 겹친다).
 * (Tailwind purge 방지: 클래스 리터럴로 기재.)
 *
 * @param {number} cols 열 수(1 또는 2)
 * @returns {string} Tailwind 그리드 열 클래스
 */
export function gridColsClass(cols) {
  return cols === 2 ? 'grid-cols-2' : 'grid-cols-1';
}
