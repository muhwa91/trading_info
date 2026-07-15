/**
 * 차트 오버레이 좌표 가시성 판정 헬퍼
 *
 * @param {number|null} coord - priceToCoordinate 반환값(px 단위, 위쪽이 0)
 * @param {number} chartHeight - 차트 캔버스 높이(px)
 * @returns {boolean} 0 이상이고 chartHeight 이하인 경우만 true (상단·하단 모두 클리핑)
 *
 * qa-tester 단위 테스트:
 *   isCoordinateVisible(null, 300)   // false
 *   isCoordinateVisible(-1, 300)     // false (상단 벗어남)
 *   isCoordinateVisible(0, 300)      // true  (상단 경계)
 *   isCoordinateVisible(150, 300)    // true  (가시 범위 내)
 *   isCoordinateVisible(300, 300)    // true  (하단 경계)
 *   isCoordinateVisible(301, 300)    // false (하단 벗어남)
 */
export function isCoordinateVisible(coord, chartHeight) {
  if (coord === null || coord === undefined) return false;
  return coord >= 0 && coord <= chartHeight;
}

/**
 * 평단 리드아웃 태그의 표시 y좌표를 계산한다 — 현재가 태그와 세로로 겹치면(가격이
 * 근접해 두 태그가 포개지면) 평단 태그를 현재가 태그에서 minGap 만큼 밀어낸다.
 * 현재가 태그(화면 최강 요소)는 제자리 고정, 평단 태그만 오프셋해 글씨 겹침을 없앤다.
 * (평단선 자체는 실제 가격 위치에 그대로 그려지고, 판독용 라벨만 비켜난다.)
 *
 * @param {number|null} avgCoord   - 평단가 y좌표(px). null 이면 null 반환.
 * @param {number|null} priceCoord - 현재가 y좌표(px). null 이면 겹침 없음 → avgCoord 그대로.
 * @param {number} minGap          - 두 태그 중심 사이 최소 간격(px, 보통 태그높이+여유).
 * @returns {number|null} 겹치지 않게 조정된 평단 태그 y좌표
 *
 * qa-tester 단위 테스트:
 *   resolveAvgTagCoordinate(null, 100, 32)  // null
 *   resolveAvgTagCoordinate(100, null, 32)  // 100 (현재가 없음 → 그대로)
 *   resolveAvgTagCoordinate(200, 100, 32)   // 200 (충분히 떨어짐 → 그대로)
 *   resolveAvgTagCoordinate(110, 100, 32)   // 132 (아래로 겹침 → 현재가+minGap)
 *   resolveAvgTagCoordinate(90, 100, 32)    // 68  (위로 겹침 → 현재가-minGap)
 *   resolveAvgTagCoordinate(100, 100, 32)   // 132 (완전 동일 → 아래로 분리)
 */
export function resolveAvgTagCoordinate(avgCoord, priceCoord, minGap) {
  if (avgCoord === null || avgCoord === undefined) return null;
  if (priceCoord === null || priceCoord === undefined) return avgCoord;
  const diff = avgCoord - priceCoord;
  if (Math.abs(diff) >= minGap) return avgCoord;
  return priceCoord + (diff >= 0 ? minGap : -minGap);
}
