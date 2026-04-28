<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Models\ArticleSubtitle;
use TnlMedia\TrendingSummary\Services\SubtitleService;

/**
 * 字幕翻譯佇列任務
 *
 * 於影片類文章進入生成階段時觸發，負責執行字幕翻譯流程：
 * 1. 透過 SubtitleService 翻譯原始字幕為繁體中文
 * 2. 分批翻譯並帶入上下文視窗，確保專有名詞一致性
 * 3. 翻譯後執行字數限制檢查
 * 4. 更新字幕模型的 translated_cues 與 translation_status
 *
 * 採用佇列重試機制：最多重試 3 次，退避間隔為 30、60、120 秒。
 */
class TranslateSubtitleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 最大重試次數。
     */
    public int $tries = 3;

    /**
     * 重試退避間隔（秒）。
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * 建立新的佇列任務實例。
     *
     * @param ArticleSubtitle $subtitle 待翻譯的字幕模型
     */
    public function __construct(
        public readonly ArticleSubtitle $subtitle,
    ) {}

    /**
     * 執行字幕翻譯流程。
     *
     * 透過 SubtitleService 翻譯字幕，將原始字幕 cues 翻譯為繁體中文。
     * 失敗時記錄錯誤並拋出例外，由佇列重試機制處理。
     */
    public function handle(): void
    {
        Log::info('TranslateSubtitleJob: 開始執行字幕翻譯流程', [
            'subtitle_id' => $this->subtitle->id,
            'article_id' => $this->subtitle->article_id,
        ]);

        try {
            /** @var SubtitleService $subtitleService */
            $subtitleService = app(SubtitleService::class);
            $subtitle = $subtitleService->translateSubtitle($this->subtitle);

            Log::info('TranslateSubtitleJob: 字幕翻譯流程完成', [
                'subtitle_id' => $subtitle->id,
                'article_id' => $subtitle->article_id,
                'translation_status' => $subtitle->translation_status,
            ]);
        } catch (\Throwable $e) {
            Log::error('TranslateSubtitleJob: 字幕翻譯流程失敗', [
                'subtitle_id' => $this->subtitle->id,
                'article_id' => $this->subtitle->article_id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
