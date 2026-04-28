<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 發佈記錄模型
 *
 * 追蹤文章在各平台（InkMagine / WordPress）的發佈狀態，
 * 每個平台獨立記錄，支援重試與錯誤追蹤。
 */
class PublishRecord extends Model
{
    /**
     * 取得含可設定前綴的資料表名稱。
     */
    public function getTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'publish_records';
    }

    /**
     * 可批量賦值的欄位。
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'platform',
        'status',
        'external_id',
        'external_url',
        'error_message',
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
            'published_at' => 'datetime',
        ];
    }

    /**
     * 取得此發佈記錄所屬的文章。
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(TrendingArticle::class, 'article_id');
    }
}
