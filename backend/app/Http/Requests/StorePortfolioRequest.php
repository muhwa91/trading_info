<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 보유 종목 추가 요청 검증.
 *
 * 미국 종목(currency=USD)이면 avg_fx_rate 가 필수.
 * KR 종목은 avg_fx_rate 를 1.0 으로 자동 설정.
 */
class StorePortfolioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stock_id'      => 'nullable|integer|exists:stocks,id',
            'symbol'        => 'nullable|string|max:20',
            'market'        => 'nullable|string|in:KR,US',
            'quantity'      => 'required|numeric|min:0.000001',
            'average_price' => 'required|numeric|min:0.000001',
            'avg_fx_rate'   => 'nullable|numeric|min:0.0001',
            'account_id'    => 'nullable|integer|exists:accounts,id',
            'source'        => 'nullable|string|in:manual,synced',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.min'      => '수량은 0보다 커야 합니다.',
            'average_price.min' => '평균 매입가는 0보다 커야 합니다.',
            'stock_id.exists'   => '존재하지 않는 종목 ID입니다.',
            'account_id.exists' => '존재하지 않는 계좌 ID입니다.',
        ];
    }

    /**
     * 기본값 주입 및 추가 검증: USD 종목인데 avg_fx_rate 누락 시 에러.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $v): void {
            $stockId = $this->input('stock_id');
            $market  = $this->input('market', '');

            // stock_id 로 통화 확인
            $currency = null;
            if ($stockId !== null) {
                $stock = Stock::find((int)$stockId);
                if ($stock !== null) {
                    $currency = $stock->currency;
                }
            } elseif (strtoupper((string)$market) === 'US') {
                $currency = 'USD';
            }

            if ($currency === 'USD' && empty($this->input('avg_fx_rate'))) {
                $v->errors()->add('avg_fx_rate', '미국 종목(USD)은 매입 시 환율(avg_fx_rate)이 필수입니다.');
            }
        });
    }
}
