/**
 * 나스닥100 선물(NQ=F) CME Globex 휴장창 판정 — 백엔드 session 필드가 없을 때의 시계 폴백.
 *
 * 백엔드 StockController 의 NQ=F session 판정과 동일 경계 — CME 실경계인 **ET 기준**:
 *   · 금 17:00 ET 마감 → 일 18:00 ET 재개 (주말 휴장)
 *   · 평일 17:00~18:00 ET 일일 유지보수(정지)
 * ※ KST 리터럴(토 06:00~월 07:00)로 굳히지 말 것 — 그 값은 여름(EDT) 한정이고
 *    겨울(EST)엔 토 07:00~월 08:00 KST 로 양끝이 1시간씩 밀려 연 ~36h 오판한다.
 *    America/New_York 로 환산하면 DST 가 자동 처리된다.
 *
 * 인자로 시각을 받지 않는 것은 의도적이다 — KST/ET 를 주입받으면 정작 틀렸던
 * **DST 환산 자체가 테스트를 우회**한다. 테스트는 vi.setSystemTime 으로 실클럭을 고정한다.
 *
 * @returns {boolean} 지금이 CME Globex 거래 시간이면 true (휴장창이면 false)
 *
 * qa-tester 단위 테스트 (실클럭 고정 후 호출):
 *   EDT 금 16:59 ET → true  · 금 17:01 ET → false
 *   EST 금 17:00 ET(토 07:00 KST) → false · 일 18:00 ET(월 08:00 KST) → true
 *   토 12:00 ET → false · 평일 17:30 ET → false · 평일 18:01 ET → true
 */
export function isNqTradingByEtClock() {
  const et = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/New_York' }));
  const dow = et.getDay(); // 0=일 … 6=토
  const t = et.getHours() * 100 + et.getMinutes();
  const isClosed =
    dow === 6 || // 토 종일 휴장
    (dow === 5 && t >= 1700) || // 금 17:00 ET 마감
    (dow === 0 && t < 1800) || // 일 18:00 ET 재개 전
    (t >= 1700 && t < 1800); // 평일 일일 유지보수
  return !isClosed;
}
