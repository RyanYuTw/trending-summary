<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 文章模板模型
 *
 * 定義摘要生成的輸出結構與設定，包含內建模板與自訂模板。
 * 內建模板（is_builtin = true）受刪除保護。
 */
class ArticleTemplate extends Model
{
    /**
     * 取得含可設定前綴的資料表名稱。
     */
    public function getTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'article_templates';
    }

    /**
     * 可批量賦值的欄位。
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_builtin',
        'output_structure',
        'settings',
    ];

    /**
     * 屬性型別轉換。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
            'output_structure' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * 取得使用此模板的所有文章。
     */
    public function articles(): HasMany
    {
        return $this->hasMany(TrendingArticle::class, 'template_id');
    }
}
