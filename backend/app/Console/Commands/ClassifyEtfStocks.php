<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ETF 종목 type 보정 커맨드.
 *
 * KRX 상장 ETF는 별도 API 미확보 상태이므로,
 * 종목명에 포함된 대표 운용사 prefix 패턴으로 best-effort 분류.
 *
 * 커버리지:
 *   KODEX, TIGER, KBSTAR, HANARO, SOL, ACE, RISE, KOSEF, ARIRANG, BNK,
 *   히어로즈, 미래에셋TIGER, PLUS ETF 등 국내 주요 ETF 브랜드
 *
 * 한계:
 *   브랜드명 없는 ETF 또는 국가/해외 ETF 일부 누락 가능.
 *   정확한 분류는 한국거래소(KRX) ETF 목록 API(isin.net 등) 활용 권장.
 *   정확도 개선은 TODO 로 남김.
 *
 * 실행:
 *   php artisan stocks:classify-etf [--dry-run]
 */
class ClassifyEtfStocks extends Command
{
    protected $signature   = 'stocks:classify-etf {--dry-run : 실제 업데이트 없이 대상 목록만 출력}';
    protected $description = 'ETF 종목 type 보정 — 종목명 패턴 기반 best-effort (KR 종목 한정)';

    /**
     * 국내 ETF 브랜드/접두사 패턴 목록.
     * 대소문자 무관하게 종목명에 포함되면 ETF 로 분류.
     */
    private const ETF_NAME_PATTERNS = [
        'KODEX',
        'TIGER',
        'KBSTAR',
        'HANARO',
        'SOL ',     // 공백 포함(삼성SOL ETF 구분)
        'ACE ',
        'RISE ',
        'KOSEF',
        'ARIRANG',
        'TREX',
        'FOCUS',
        'PLUS ETF',
        'SMART',
        'BNK',
        'TIMEFOLIO',
        'HI ETF',
        '히어로즈',
        'KTOP',
        'LION',
        '레버리지',
        '인버스',
        'NASDAQ',
        'S&P500',
        'MSCI',
    ];

    public function handle(): int
    {
        $isDryRun = (bool)$this->option('dry-run');
        $this->info($isDryRun ? '[DRY-RUN] 업데이트 없이 대상 목록만 출력합니다.' : 'ETF type 보정을 시작합니다.');

        // KR 종목 중 아직 stock 으로 분류된 것 대상
        $stocks = Stock::where('market', 'KR')->where('type', 'stock')->get(['id', 'symbol', 'name']);

        $targets = [];
        foreach ($stocks as $stock) {
            $nameLower = mb_strtolower($stock->name, 'UTF-8');

            foreach (self::ETF_NAME_PATTERNS as $pattern) {
                if (mb_strpos($nameLower, mb_strtolower($pattern, 'UTF-8'), 0, 'UTF-8') !== false) {
                    $targets[] = $stock;
                    break;
                }
            }
        }

        $count = count($targets);
        $this->info("대상 종목 수: {$count}건");

        if ($isDryRun) {
            foreach ($targets as $t) {
                $this->line("  [{$t->symbol}] {$t->name}");
            }
            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('보정할 종목이 없습니다.');
            return self::SUCCESS;
        }

        $ids = array_column($targets, 'id');

        DB::table('stocks')->whereIn('id', $ids)->update(['type' => 'etf']);

        $this->info("ETF 분류 완료: {$count}건 type='etf' 으로 갱신.");
        $this->warn('주의: 종목명 패턴 기반 best-effort — 정확도는 KRX ETF 목록 API 활용 시 개선 가능.');

        return self::SUCCESS;
    }
}
