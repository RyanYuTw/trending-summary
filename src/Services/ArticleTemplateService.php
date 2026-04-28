<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use TnlMedia\TrendingSummary\Models\ArticleTemplate;

/**
 * 文章模板管理服務
 *
 * 負責模板的 CRUD 操作、結構驗證，以及內建模板的刪除保護。
 * 內建模板（is_builtin = true）不可被刪除。
 */
class ArticleTemplateService
{
    /**
     * 取得所有模板清單。
     *
     * @return Collection<int, ArticleTemplate>
     */
    public function list(): Collection
    {
        return ArticleTemplate::orderBy('is_builtin', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * 依 slug 取得模板。
     *
     * @param string $slug 模板 slug
     * @return ArticleTemplate|null 找到的模板，不存在時回傳 null
     */
    public function getBySlug(string $slug): ?ArticleTemplate
    {
        return ArticleTemplate::where('slug', $slug)->first();
    }

    /**
     * 依 ID 取得模板。
     *
     * @param int $id 模板 ID
     * @return ArticleTemplate|null 找到的模板，不存在時回傳 null
     */
    public function getById(int $id): ?ArticleTemplate
    {
        return ArticleTemplate::find($id);
    }

    /**
     * 建立自訂模板。
     *
     * 驗證模板資料結構後建立新模板，自訂模板的 is_builtin 固定為 false。
     *
     * @param array<string, mixed> $data 模板資料
     * @return ArticleTemplate 建立的模板
     *
     * @throws ValidationException 當資料驗證失敗時
     */
    public function create(array $data): ArticleTemplate
    {
        $validated = $this->validate($data);

        return ArticleTemplate::create([
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'description' => $validated['description'],
            'is_builtin' => false,
            'output_structure' => $validated['output_structure'],
            'settings' => $validated['settings'],
        ]);
    }

    /**
     * 更新現有模板。
     *
     * 驗證更新資料後套用變更。驗證時排除自身的 slug 唯一性檢查。
     *
     * @param ArticleTemplate $template 要更新的模板
     * @param array<string, mixed> $data 更新資料
     * @return ArticleTemplate 更新後的模板
     *
     * @throws ValidationException 當資料驗證失敗時
     */
    public function update(ArticleTemplate $template, array $data): ArticleTemplate
    {
        $validated = $this->validate($data, $template->id);

        $template->update([
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'description' => $validated['description'],
            'output_structure' => $validated['output_structure'],
            'settings' => $validated['settings'],
        ]);

        return $template->refresh();
    }

    /**
     * 刪除模板。
     *
     * 內建模板（is_builtin = true）不可刪除，嘗試刪除時拋出例外。
     *
     * @param ArticleTemplate $template 要刪除的模板
     * @return bool 刪除成功回傳 true
     *
     * @throws \RuntimeException 當嘗試刪除內建模板時
     */
    public function delete(ArticleTemplate $template): bool
    {
        if ($template->is_builtin) {
            throw new \RuntimeException('內建模板不可刪除。');
        }

        return (bool) $template->delete();
    }

    /**
     * 驗證模板資料結構。
     *
     * 驗證規則：
     * - slug：必填、字串、最長 100 字元、唯一、kebab-case 格式
     * - name：必填、字串、最長 200 字元
     * - description：必填、字串
     * - output_structure：必填、陣列，每個項目須含 field、type、label、required
     * - settings：必填、陣列，須含 tone、use_emoji、max_paragraphs、max_total_chars
     *
     * @param array<string, mixed> $data 待驗證的資料
     * @param int|null $excludeId 排除唯一性檢查的模板 ID（更新時使用）
     * @return array<string, mixed> 驗證通過的資料
     *
     * @throws ValidationException 當驗證失敗時
     */
    public function validate(array $data, ?int $excludeId = null): array
    {
        $table = (new ArticleTemplate())->getTable();

        $slugUniqueRule = 'unique:' . $table . ',slug';
        if ($excludeId !== null) {
            $slugUniqueRule .= ',' . $excludeId;
        }

        $validator = Validator::make($data, [
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
        ], [
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
        ]);

        return $validator->validate();
    }
}
