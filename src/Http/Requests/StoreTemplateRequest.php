<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use TnlMedia\TrendingSummary\Models\ArticleTemplate;

/**
 * 模板建立/更新請求驗證
 *
 * 委派至 ArticleTemplateService::validate() 的驗證規則，
 * 確保模板結構（slug、name、output_structure、settings）符合要求。
 */
class StoreTemplateRequest extends FormRequest
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
        $table = (new ArticleTemplate())->getTable();

        // 更新時排除自身的 slug 唯一性檢查
        $excludeId = $this->route('template')?->id ?? null;
        $slugUniqueRule = 'unique:' . $table . ',slug';
        if ($excludeId !== null) {
            $slugUniqueRule .= ',' . $excludeId;
        }

        return [
            'slug' => ['required', 'string', 'max:100', $slugUniqueRule, 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string'],
            'output_structure' => ['required', 'array', 'min:1'],
            'output_structure.*.field' => ['required', 'string'],
            'output_structure.*.type' => ['required', 'string'],
            'output_structure.*.label' => ['required', 'string'],
            'output_structure.*.required' => ['required', 'boolean'],
            'settings' => ['required', 'array'],
            'settings.tone' => ['required', 'string'],
            'settings.use_emoji' => ['required', 'boolean'],
            'settings.max_paragraphs' => ['required', 'integer', 'min:1'],
            'settings.max_total_chars' => ['required', 'integer', 'min:1'],
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
            'slug.regex' => 'slug 必須為 kebab-case 格式（僅含小寫英文字母、數字與連字號）。',
            'output_structure.min' => 'output_structure 至少需包含一個欄位定義。',
            'output_structure.*.field.required' => 'output_structure 每個項目須包含 field 欄位。',
            'output_structure.*.type.required' => 'output_structure 每個項目須包含 type 欄位。',
            'output_structure.*.label.required' => 'output_structure 每個項目須包含 label 欄位。',
            'output_structure.*.required.required' => 'output_structure 每個項目須包含 required 欄位。',
            'settings.tone.required' => 'settings 須包含 tone 設定。',
            'settings.use_emoji.required' => 'settings 須包含 use_emoji 設定。',
            'settings.max_paragraphs.required' => 'settings 須包含 max_paragraphs 設定。',
            'settings.max_total_chars.required' => 'settings 須包含 max_total_chars 設定。',
        ];
    }
}
