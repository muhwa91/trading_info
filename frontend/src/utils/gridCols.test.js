/**
 * 하단 차트 그리드 열 수 토글 로직 단위 테스트 — utils/gridCols.js 실경계 가드
 *
 * 이웃 App.indexHeader.test.js 가 utils/nqSession.js 를 import 하는 것과 동일 패턴으로,
 * App.vue 와 이 테스트가 **같은** gridColsClass(utils/gridCols.js)를 import 한다.
 * 인라인 복제본이 없으므로 gridCols.js 반환값을 바꾸면 이 테스트가 실제로 깨진다(tautology 아님).
 * (단언은 현행 스킴 grid-cols-2 에 맞춰져 있다.)
 */

import { describe, it, expect } from 'vitest';
import { gridColsClass } from './gridCols.js';

describe('gridColsClass — 사용자 선택 열 수 → Tailwind 클래스 (utils/gridCols.js)', () => {
  it('1(1차트) → 세로 1열 (grid-cols-1)', () => {
    expect(gridColsClass(1)).toBe('grid-cols-1');
  });

  it('2(4차트) → 폭 무관 2열 고정 (grid-cols-2)', () => {
    expect(gridColsClass(2)).toBe('grid-cols-2');
  });

  it('4차트는 grid-cols-1 로 시작하지 않는다(폭 무관 2열 고정, 좁은 폭에서도 1열로 강등 안 함)', () => {
    expect(gridColsClass(2)).not.toMatch(/^grid-cols-1/);
  });

  it('반응형 자동 3열(lg:grid-cols-3)은 어느 모드에도 없다', () => {
    [1, 2].forEach(c => expect(gridColsClass(c)).not.toContain('lg:grid-cols-3'));
  });

  it('경계값(0·3·undefined)은 안전하게 grid-cols-1 로 폴백한다', () => {
    expect(gridColsClass(0)).toBe('grid-cols-1');
    expect(gridColsClass(3)).toBe('grid-cols-1');
    expect(gridColsClass(undefined)).toBe('grid-cols-1');
  });
});
