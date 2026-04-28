<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 趨勢文章主模型
 *
 * 代表從 RSS feed 抓取並經過篩選、生成、審核流程的文章。
 * 包含文章的完整生命週期狀態與所有關聯資料。
 */
class TrendingArticle extends Model
{
    /**
     * 取得含可設定前綴的資料表名稱。
     */
    public function getTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'trending_articles';
    }

    /**
     * 可批量賦值的欄位。
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'title',
        'original_title',
        'original_url',
        'source_name',
        'content_type',
        'content_body',
        'summary',
        'selected_title',
        'status',
        'relevance_score',
        'quality_score',
        'is_auto_published',
        'template_id',
        'trend_keywords',
        'published_at',
    ];

    /**
     * 屬性型別轉換。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relevance_score' => 'float',
            'quality_score' => 'integer',
            'is_auto_published' => 'boolean',
            'trend_keywords' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * 取得文章的 AI 生成標題候選列表。
     */
    public function generatedTitles(): HasMany
    {
        return $this->hasMany(GeneratedTitle::class, 'article_id');
    }

    /**
     * 取得文章的配圖資料。
     */
    public function image(): HasOne
    {
        return $this->hasOne(ArticleImage::class, 'article_id');
    }

    /**
     * 取得文章的字幕資料（影片類型適用）。
     */
    public function subtitle(): HasOne
    {
        return $this->hasOne(ArticleSubtitle::class, 'article_id');
    }

    /**
     * 取得文章的 SEO 資料。
     */
    public function seo(): HasOne
    {
        return $this->hasOne(ArticleSeo::class, 'article_id');
    }

    /**
     * 取得文章的品質檢查報告。
     */
    public function qualityReport(): HasOne
    {
        return $this->hasOne(QualityReport::class, 'article_id');
    }

    /**
     * 取得文章的發佈記錄列表。
     */
    public function publishRecords(): HasMany
    {
        return $this->hasMany(PublishRecord::class, 'article_id');
    }

    /**
     * 取得文章使用的模板。
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ArticleTemplate::class, 'template_id');
    }
}
