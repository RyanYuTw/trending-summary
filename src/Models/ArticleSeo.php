<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 文章 SEO 資料模型
 *
 * 儲存文章的完整 SEO 資訊，包含 Meta 標籤、Open Graph、Twitter Card、
 * JSON-LD 結構化資料、FAQ、AIO 優化檢查清單等。
 */
class ArticleSeo extends Model
{
    /**
     * 取得含可設定前綴的資料表名稱。
     */
    public function getTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'article_seo';
    }

    /**
     * 可批量賦值的欄位。
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'meta_title',
        'meta_description',
        'slug',
        'canonical_url',
        'og_data',
        'twitter_data',
        'json_ld',
        'focus_keyword',
        'secondary_keywords',
        'direct_answer_block',
        'faq_items',
        'faq_schema',
        'aio_checklist',
    ];

    /**
     * 屬性型別轉換。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'og_data' => 'array',
            'twitter_data' => 'array',
            'json_ld' => 'array',
            'secondary_keywords' => 'array',
            'faq_items' => 'array',
            'faq_schema' => 'array',
            'aio_checklist' => 'array',
        ];
    }

    /**
     * 取得此 SEO 資料所屬的文章。
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(TrendingArticle::class, 'article_id');
    }
}
