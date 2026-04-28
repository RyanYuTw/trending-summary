<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\ImageSourceService;

/**
 * 圖片擷取佇列任務
 *
 * 於摘要生成完成後觸發，負責執行圖片版權判斷與配圖搜尋流程：
 * 1. 透過 ImageSourceService 解析文章配圖來源
 * 2. 判斷原始圖片是否為 CNA 域名（可直接使用）
 * 3. 非 CNA 圖片則透過 InkMagine Gateway API 搜尋合法配圖
 * 4. 無可用配圖時標記 needs_manual 待人工補圖
 *
 * 採用佇列重試機制：最多重試 3 次，退避間隔為 30、60、120 秒。
 */
class FetchImageJob implements ShouldQueue
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
     * @param TrendingArticle $article 待配圖的趨勢文章
     */
    public function __construct(
        public readonly TrendingArticle $article,
    ) {}

    /**
     * 執行圖片擷取流程。
     *
     * 透過 ImageSourceService 解析文章配圖來源，執行版權判斷與配圖搜尋。
     * 失敗時記錄錯誤並拋出例外，由佇列重試機制處理。
     */
    public function handle(): void
    {
        Log::info('FetchImageJob: 開始執行圖片擷取流程', [
            'article_id' => $this->article->id,
        ]);

        try {
            /** @var ImageSourceService $imageSourceService */
            $imageSourceService = app(ImageSourceService::class);
            $image = $imageSourceService->resolveImage($this->article);

            Log::info('FetchImageJob: 圖片擷取流程完成', [
                'article_id' => $this->article->id,
                'source_provider' => $image->source_provider,
                'needs_manual' => $image->needs_manual,
            ]);
        } catch (\Throwable $e) {
            Log::error('FetchImageJob: 圖片擷取流程失敗', [
                'article_id' => $this->article->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
