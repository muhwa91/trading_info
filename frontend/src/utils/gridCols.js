/**
 * 4차트 그리드 열 클래스 — 사용자가 명시 선택한 열 수(1=세로 1열/차트 크게, 2=2×2)를 Tailwind 클래스로.
 * 4차트 모드는 컨테이너 쿼리 기반: 좁으면 1열 세로 스택, 컨테이너가 넓으면(≥1056px = 카드 520×2 + gap16) 2열.
 * 제약이 뷰포트가 아니라 컨테이너 폭(좌측 레일 때문)이라 lg:·min-[Npx]: 뷰포트 브레이크포인트가 아닌
 * @min-[1056px]: 컨테이너 쿼리를 쓴다(그리드 래퍼에 @container = container-type: inline-size 전제).
 * (Tailwind purge 방지: 클래스 리터럴로 기재.)
 *
 * @param {number} cols 열 수(1 또는 2)
 * @returns {string} Tailwind 그리드 열 클래스
 */
export function gridColsClass(cols) {
  return cols === 2 ? 'grid-cols-1 @min-[1056px]:grid-cols-2' : 'grid-cols-1';
}
