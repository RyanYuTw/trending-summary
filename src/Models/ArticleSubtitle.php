<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 文章字幕模型
 *
 * 儲存影片類文章的字幕資料，包含原始字幕 cues、翻譯後 cues、
 * 翻譯狀態、以及輸出的 SRT/VTT 檔案路徑。
 */
class ArticleSubtitle extends Model
{
    /**
     * 取得含可設定前綴的資料表名稱。
     */
    public function getTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'article_subtitles';
    }

    /**
     * 可批量賦值的欄位。
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'original_language',
        'source_format',
        'original_cues',
        'translated_cues',
        'translation_status',
        'srt_path',
        'vtt_path',
    ];

    /**
     * 屬性型別轉換。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_cues' => 'array',
            'translated_cues' => 'array',
        ];
    }

    /**
     * 取得此字幕所屬的文章。
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(TrendingArticle::class, 'article_id');
    }
}
