<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 文章審核請求驗證
 *
 * 驗證審核操作（approve / reject / skip）與可選的退回原因。
 * 也支援更新 selected_title 欄位。
 */
class ReviewArticleRequest extends FormRequest
{
    /**
     * 判斷使用者是否有權限發送此請求。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 取得驗證規則。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'required', 'string', 'in:approve,reject,skip'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'selected_title' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * 取得自訂驗證訊息。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.in' => 'action 必須為 approve、reject 或 skip。',
        ];
    }
}
