/**
 * 하단 차트 그리드 열 수 토글 로직 단위 테스트
 *
 * 반응형 자동 열 배치(등록 개수 기반 GRID_COLS_MAP)를 제거하고,
 * 사용자가 1차트(세로 1열/크게) / 4차트(2×2)를 명시 선택하는 방식으로 전환.
 * App.vue 의 gridColsClass 로직을 인라인 복제해 컴포넌트 마운트 없이 순수 함수로 검증한다.
 */

import { describe, it, expect } from 'vitest';

// ── App.vue 와 동일한 로직 복제 ────────────────────────────────
function gridColsClass(cols) {
  return cols === 2 ? 'grid-cols-1 md:grid-cols-2' : 'grid-cols-1';
}
// ────────────────────────────────────────────────────────────────

describe('gridColsClass — 사용자 선택 열 수 → Tailwind 클래스', () => {
  it('1(1차트) → 세로 1열 (grid-cols-1)', () => {
    expect(gridColsClass(1)).toBe('grid-cols-1');
  });

  it('2(4차트) → 2×2 (grid-cols-1 md:grid-cols-2)', () => {
    expect(gridColsClass(2)).toBe('grid-cols-1 md:grid-cols-2');
  });

  it('모든 모드가 grid-cols-1 으로 시작(모바일/좁은 폭에서 1열로 자연 강등)', () => {
    [1, 2].forEach(c => expect(gridColsClass(c)).toMatch(/^grid-cols-1/));
  });

  it('반응형 자동 3열(lg:grid-cols-3)은 어느 모드에도 없다', () => {
    [1, 2].forEach(c => expect(gridColsClass(c)).not.toContain('lg:grid-cols-3'));
  });
});
