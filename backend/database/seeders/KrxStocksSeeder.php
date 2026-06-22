<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class KrxStocksSeeder extends Seeder
{
    /**
     * storage/app/krx_stocks.json 에서 국내 종목을 stocks 테이블에 upsert 한다.
     *
     * JSON 구조: [{ "ticker": "005930.KS", "name": "삼성전자", "code": "005930", "market": "KOSPI|KOSDAQ|..." }]
     *
     * ETF 분류 주의:
     *   - krx_stocks.json 에는 ETF 구분 필드가 없고 이름도 한글이라
     *     영문 브랜드(KODEX/TIGER 등) 휴리스틱 매칭이 불가능하다.
     *   - 따라서 모든 종목을 type='stock' 으로 저장한다.
     *   - STEP 2 이후 별도 ETF 마스터 파일이 제공되면 upsert 로 type='etf' 업데이트 가능.
     *
     * 미국 종목(US market) 시딩:
     *   - 이 시더에서는 미국 종목을 시딩하지 않는다.
     *   - 관심/보유 종목 추가 시 lazy 생성(STEP 2~3)으로 처리한다.
     *
     * market 매핑:
     *   - KOSPI / KOSDAQ / KOSDAQ GLOBAL / KONEX → exchange 컬럼에 원문 보존, market='KR'
     */
    public function run(): void
    {
        $path = storage_path('app/krx_stocks.json');

        if (! file_exists($path)) {
            $this->command->warn('krx_stocks.json 파일을 찾을 수 없습니다: ' . $path);

            return;
        }

        $raw = file_get_contents($path);
        $items = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (empty($items)) {
            $this->command->warn('krx_stocks.json 가 비어 있습니다.');

            return;
        }

        $now = now();
        $chunkSize = 500;
        $total = 0;

        $chunks = array_chunk($items, $chunkSize);

        foreach ($chunks as $chunk) {
            $rows = [];

            foreach ($chunk as $item) {
                $code = $item['code'] ?? '';
                $name = $item['name'] ?? '';
                $market = $item['market'] ?? 'KOSPI'; // KOSPI|KOSDAQ|KOSDAQ GLOBAL|KONEX

                if ($code === '' || $name === '') {
                    continue;
                }

                $rows[] = [
                    'symbol' => $code,
                    'name' => $name,
                    'type' => 'stock',  // ETF 구분 데이터 없음 — 주석 참고
                    'market' => 'KR',
                    'exchange' => $market,  // KOSPI / KOSDAQ 원문 보존
                    'currency' => 'KRW',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! empty($rows)) {
                DB::table('stocks')->upsert(
                    $rows,
                    ['symbol', 'market'],          // 고유 키
                    ['name', 'exchange', 'updated_at'] // 충돌 시 갱신 컬럼
                );
                $total += count($rows);
            }
        }

        $this->command->info("국내 종목 시딩 완료: {$total}건 (type=stock 고정, ETF 미분류 — krx_stocks.json ETF 구분 없음)");
    }
}
