<?php

declare(strict_types=1);

namespace App\Services\Quote;

use App\Models\Stock;

/**
 * 종목 한 개의 현재가를 세션별로 조회하는 계약.
 *
 * 반환 배열:
 *   price          float  – 종목 원래 통화 기준 현재가
 *   change_amount  float  – 전일 대비 등락폭
 *   change_percent float  – 전일 대비 등락률(%)
 *   recorded_at    string – 가격 기준 시각 (Y-m-d H:i:s, UTC)
 *
 * 키 미설정·API 장애 시 null 반환 → graceful 처리.
 */
interface QuoteProviderInterface
{
    /**
     * @return array{price:float,change_amount:float,change_percent:float,recorded_at:string}|null
     */
    public function fetchQuote(Stock $stock, string $session): ?array;
}
