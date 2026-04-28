<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * 批次操作請求驗證
 *
 * 驗證批次操作所需的 article_ids 陣列與 action（approve / reject / skip）。
 */
class BatchActionRequest extends FormRequest
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
        $table = (new TrendingArticle())->getTable();

        return [
            'article_ids' => ['required', 'array', 'min:1'],
            'article_ids.*' => ['required', 'integer', 'exists:' . $table . ',id'],
            'action' => ['required', 'string', 'in:approve,reject,skip'],
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
            'article_ids.required' => '請提供至少一篇文章 ID。',
            'article_ids.min' => '請提供至少一篇文章 ID。',
            'article_ids.*.exists' => '部分文章 ID 不存在。',
            'action.in' => 'action 必須為 approve、reject 或 skip。',
        ];
    }
}
