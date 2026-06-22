<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 보유 종목 수정 요청 검증.
 */
class UpdatePortfolioRequest extends FormRequest
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
            'quantity'      => 'sometimes|numeric|min:0.000001',
            'average_price' => 'sometimes|numeric|min:0.000001',
            'avg_fx_rate'   => 'sometimes|numeric|min:0.0001',
            'source'        => 'sometimes|string|in:manual,synced',
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
            'avg_fx_rate.min'   => '환율은 0보다 커야 합니다.',
        ];
    }
}
