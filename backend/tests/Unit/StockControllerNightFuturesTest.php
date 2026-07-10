<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * StockController::isKrNightFuturesActive() — KRX 야간선물 세션 판정 회귀 가드.
 *
 * 배경 (버그):
 *   야간선물은 개장일 18:00 ~ 익일 05:00 운영. 새벽(00~05시)은 "전일 저녁 세션의 연장"이므로
 *   거래일 판정을 **전일 기준**으로 해야 한다. 옛 코드는 새벽에도 isKrTradingDay(time()=오늘)로 판정해
 *   토요일 새벽(=금요일 밤 세션)을 '오늘=비거래일'로 잘라 '장마감'으로 오판정했다.
 *
 * 검증 방식:
 *   isKrNightFuturesActive() 는 실시각(new DateTime('now'))에 의존해 시각 주입이 불가하므로
 *   행위(behavioral) 테스트가 비결정적이다. StockControllerTransmitPathTest 와 동일하게
 *   소스 구조를 검사해 수정된 분기 로직이 회귀하지 않도록 고정한다.
 *   (근본 해결 = 클럭 주입 리팩터링 후 행위 테스트. 커버리지 갭으로 보고됨.)
 */
class StockControllerNightFuturesTest extends TestCase
{
    private function getMethodSource(): string
    {
        $path = __DIR__ . '/../../app/Http/Controllers/StockController.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'StockController.php 읽기 실패');

        $marker = 'private function isKrNightFuturesActive';
        $pos    = strpos((string) $src, $marker);
        $this->assertNotFalse($pos, 'isKrNightFuturesActive() 정의를 찾을 수 없음');

        return substr((string) $src, $pos, 800);
    }

    /** @test */
    public function testEveningBranchUsesTodayTradingDay(): void
    {
        $src = $this->getMethodSource();

        // 저녁 세션(18시 이후)은 오늘 거래일로 판정해야 한다.
        $this->assertMatchesRegularExpression(
            '/\$hour\s*>=\s*18/',
            $src,
            '저녁 세션 경계($hour >= 18) 분기가 없음.'
        );
        $this->assertMatchesRegularExpression(
            '/\$hour\s*>=\s*18.*?isKrTradingDay\s*\(\s*time\(\)\s*\)/s',
            $src,
            '저녁 세션 분기가 isKrTradingDay(time()) (오늘) 을 사용하지 않음.'
        );
    }

    /**
     * @test
     * 핵심 회귀 가드: 새벽(00~05시) 분기는 전일 거래일 기준이어야 한다.
     * time() 로 회귀하면 토요일 새벽=금요일 밤 세션이 '장마감'으로 잘린다.
     */
    public function testDawnBranchUsesPreviousDayTradingDay(): void
    {
        $src = $this->getMethodSource();

        // 새벽 경계($hour < 5) 분기 존재
        $this->assertMatchesRegularExpression(
            '/\$hour\s*<\s*5/',
            $src,
            '새벽 세션 경계($hour < 5) 분기가 없음.'
        );

        // 새벽 분기는 반드시 전일(-1 day) 기준으로 거래일을 판정해야 한다.
        $this->assertMatchesRegularExpression(
            '/\$hour\s*<\s*5.*?isKrTradingDay\s*\(\s*strtotime\(\s*[\'"]-1 day[\'"]\s*\)\s*\)/s',
            $src,
            '새벽 세션 분기가 isKrTradingDay(strtotime(\'-1 day\')) (전일) 을 사용하지 않음. ' .
            'time()(오늘)로 회귀 시 토요일 새벽=금요일 밤 세션이 장마감으로 오판정된다.'
        );
    }

    /** @test */
    public function testDaytimeReturnsFalse(): void
    {
        $src = $this->getMethodSource();

        // 두 경계(18시 이후 / 5시 이전) 모두 아니면 야간선물 미운영(false).
        $this->assertMatchesRegularExpression(
            '/return\s+false\s*;/',
            $src,
            '주간(05~18시) 기본 return false 가 없음 — 낮에도 야간선물이 활성으로 오판정될 수 있음.'
        );
    }
}
