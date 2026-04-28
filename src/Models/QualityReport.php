<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 品質檢查報告模型
 *
 * 儲存 AI 品質檢查的結果，包含拼寫問題、用語問題、敏感用語、
 * 事實參照、SEO 建議、AIO 檢查清單與總分。
 */
class QualityReport extends Model
{
    /**
     * 取得含可設定前綴的資料表名稱。
     */
    public function getTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'quality_reports';
    }

    /**
     * 可批量賦值的欄位。
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'spelling_issues',
        'terminology_issues',
        'sensitive_terms',
        'fact_references',
        'seo_suggestions',
        'aio_checklist',
        'overall_score',
    ];

    /**
     * 屬性型別轉換。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spelling_issues' => 'array',
            'terminology_issues' => 'array',
            'sensitive_terms' => 'array',
            'fact_references' => 'array',
            'seo_suggestions' => 'array',
            'aio_checklist' => 'array',
            'overall_score' => 'integer',
        ];
    }

    /**
     * 取得此品質報告所屬的文章。
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(TrendingArticle::class, 'article_id');
    }
}
