<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관심종목 추가 요청 검증.
 */
class StoreWatchlistRequest extends FormRequest
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
            'stock_id'   => 'nullable|integer|exists:stocks,id',
            'symbol'     => 'nullable|string|max:20',
            'market'     => 'nullable|string|in:KR,US',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stock_id.exists' => '존재하지 않는 종목 ID입니다.',
        ];
    }
}
