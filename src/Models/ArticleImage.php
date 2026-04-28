<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 文章配圖模型
 *
 * 儲存文章的封面圖片資訊，包含圖片來源（CNA / InkMagine / 手動上傳）、
 * 各尺寸版本、以及是否需要人工補圖的標記。
 */
class ArticleImage extends Model
{
    /**
     * 取得含可設定前綴的資料表名稱。
     */
    public function getTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'article_images';
    }

    /**
     * 可批量賦值的欄位。
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'url',
        'thumbnail_url',
        'source_provider',
        'caption',
        'versions',
        'needs_manual',
    ];

    /**
     * 屬性型別轉換。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'versions' => 'array',
            'needs_manual' => 'boolean',
        ];
    }

    /**
     * 取得此圖片所屬的文章。
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(TrendingArticle::class, 'article_id');
    }
}
