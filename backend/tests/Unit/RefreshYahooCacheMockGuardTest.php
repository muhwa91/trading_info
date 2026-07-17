<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 회귀 테스트 — 버그 #1 (NQ=F 차트 고착) + 버그 #2 (isUsMarketOpen 주간거래 경계 불일치)
 *
 * 버그 #1: refreshYahooCache() 에서 Yahoo API 실패 시 getMockStockData() 반환값이
 *   Cache::forever(_last) 에 영구 저장되는 문제.
 *   stale-restore 가 목 데이터를 5초 재주입 → 차트 고착.
 *   수정: source 에 'Mock' 포함 또는 candles 비어있으면 _last 저장 금지.
 *
 * 버그 #2(주간거래 경계): 이 파일에서 제거됨 — 소스 grep 테스트라 '330' 을 정답으로 계약화하고 있었다.
 *   실측 결과 330 이 오히려 틀린 값이었다(2026-07-17 교정 → 04:00). 동작 기반 대체 위치는 아래 §버그 #2 참조.
 */
class RefreshYahooCacheMockGuardTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // 버그 #1 — refreshYahooCache Mock 가드
    // ──────────────────────────────────────────────────────────────

    /**
     * refreshYahooCache 소스에 'Mock' 소스 검사 가드가 존재한다.
     *
     * getMockStockData() 는 source='Mock (...)' 을 반환한다.
     * refreshYahooCache() 는 _last 저장 전 source 에 'Mock' 이 포함되면 저장을 건너뛰어야 한다.
     */
    #[Test]
    public function test_refresh_yahoo_cache_has_mock_guard(): void
    {
        $source = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        // 'Mock' 문자열 검사가 메서드 소스에 있어야 한다
        $this->assertStringContainsString(
            'Mock',
            $methodSrc,
            'refreshYahooCache 에 Mock 소스 방어 가드가 없음 — ' .
            'Yahoo 실패 시 getMockStockData 반환값이 _last 에 영구 저장될 수 있음'
        );
    }

    /**
     * refreshYahooCache 소스에 candles 비어있을 경우 저장 금지 가드가 존재한다.
     */
    #[Test]
    public function test_refresh_yahoo_cache_has_empty_candles_guard(): void
    {
        $source = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        // 'candles' 존재 여부 검사 코드가 있어야 한다
        $this->assertStringContainsString(
            'candles',
            $methodSrc,
            'refreshYahooCache 에 candles 비어있음 방어 가드가 없음'
        );
    }

    /**
     * refreshYahooCache 에서 _last 저장이 복합 조건(!$isMock && $hasCandles)으로 보호된다.
     */
    #[Test]
    public function test_refresh_yahoo_cache_last_storage_is_conditional(): void
    {
        $source = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        // Cache::forever(_last 저장)와 isMock/$hasCandles 조건이 모두 존재해야 한다
        $this->assertStringContainsString(
            'Cache::forever',
            $methodSrc,
            'refreshYahooCache 에 Cache::forever(_last 저장)가 없음'
        );

        $foreverPos = strpos($methodSrc, 'Cache::forever');
        $mockPos = strpos($methodSrc, 'Mock');

        $this->assertNotFalse($mockPos, 'refreshYahooCache 에 Mock 검사 코드가 없음');

        // Mock 검사 조건이 Cache::forever 보다 앞에 위치해야 한다 (guard-before-action)
        $this->assertLessThan(
            (int) $foreverPos,
            (int) $mockPos,
            'Mock 검사 가드가 Cache::forever(_last 저장) 보다 뒤에 위치함 — 가드가 저장 전에 있어야 함'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 버그 #2 — isUsMarketOpen 주간거래 경계: 이 파일에서 삭제됨 (2026-07-17)
    //
    //   있던 테스트 2개(testIsUsMarketOpenOvernightBoundaryMatchesGetUsSession ·
    //   testAllOvernightBoundariesAreConsistentAt330)는 **소스 텍스트에 '330' 이 있는지 grep** 했다.
    //   그래서 상수가 틀렸다는 걸 말할 수 없었고 — 오히려 '330' 을 **정답으로 계약화**해
    //   교정(→400)을 가로막는 쪽으로 작동했다. 실측 결과 330 은 허구였다(03:30~04:00 91/91분 체결).
    //   소스 grep 은 "코드가 이렇게 적혀 있다"만 말한다. "코드가 옳다"는 절대 말하지 못한다.
    //
    //   대체(동작 기반):
    //     - 경계 04:00·19:50 자체 → MarketSessionServiceTest (토스 캘린더 픽스처가 정본)
    //     - 기준가·2줄 영향       → TossChangeCalculatorTest 케이스 1·2 (실측 리터럴)
    //   StockController::isUsMarketOpen 은 그 자체가 세션 모형 복제본이라 남은 리스크가 있다 —
    //   경계 04:00 미만에선 거래일 게이트로 흡수돼 동작 차이가 안 드러나므로 동작 테스트가 불가능하다.
    //   근본 해법은 grep 테스트 부활이 아니라 MarketSessionService 위임(StockController 소유자 회부).
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────────────────────

    private function getServerSource(): string
    {
        $path = __DIR__ . '/../../app/Console/Commands/WebSocketAgentServer.php';
        $src = file_get_contents($path);
        $this->assertNotFalse($src, 'WebSocketAgentServer.php 읽기 실패');

        return (string) $src;
    }

    /**
     * 소스 파일에서 특정 메서드 블록을 중괄호 카운팅으로 추출한다.
     */
    private function extractMethodSource(string $source, string $methodName): string
    {
        $pos = strpos($source, "function {$methodName}(");
        if ($pos === false) {
            return '';
        }
        $openPos = strpos($source, '{', $pos);
        if ($openPos === false) {
            return '';
        }

        $depth = 0;
        $end = $openPos;
        $length = strlen($source);

        for ($i = $openPos; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return substr($source, $pos, $end - $pos + 1);
    }
}
