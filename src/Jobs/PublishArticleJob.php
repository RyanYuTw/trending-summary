<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\PublisherInterface;
use TnlMedia\TrendingSummary\DataTransferObjects\PublishResult;
use TnlMedia\TrendingSummary\Events\ArticlePublished;
use TnlMedia\TrendingSummary\Models\PublishRecord;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\Publishers\InkMaginePublisher;
use TnlMedia\TrendingSummary\Services\Publishers\WordPressPublisher;

/**
 * 文章發佈佇列任務
 *
 * 負責將已核准的文章發佈至指定目標平台（InkMagine / WordPress）。
 * 對每個選定的目標平台執行發佈，成功時記錄 PublishRecord，
 * 失敗時記錄錯誤並允許重試。各平台獨立追蹤發佈狀態。
 *
 * 採用指數退避重試策略：最多重試 3 次，退避間隔為 30、60、120 秒。
 *
 * 支援 draft 模式：透過 $options['status'] 指定 'draft' 或 'published'。
 *
 * @see \TnlMedia\TrendingSummary\Contracts\PublisherInterface
 */
class PublishArticleJob implements ShouldQueue
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
     * 重試退避間隔（秒）：指數退避 30s → 60s → 120s。
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * 建立新的佇列任務實例。
     *
     * @param  TrendingArticle  $article  待發佈的趨勢文章
     * @param  string  $platform  目標發佈平台（'inkmagine' / 'wordpress'）
     * @param  array<string, mixed>  $options  額外選項（如 'status' => 'draft'|'published'）
     */
    public function __construct(
        public readonly TrendingArticle $article,
        public readonly string $platform,
        public readonly array $options = [],
    ) {}

    /**
     * 執行文章發佈流程。
     *
     * 處理步驟：
     * 1. 建立或取得此文章 + 平台的 PublishRecord，狀態設為 'publishing'
     * 2. 依平台名稱解析對應的 Publisher 實例
     * 3. 呼叫 publisher->publish() 執行發佈
     * 4. 成功 → 更新 PublishRecord（status=published, external_id, external_url, published_at）
     * 5. 失敗 → 更新 PublishRecord（status=failed, error_message），拋出例外觸發重試
     * 6. 成功 → 觸發 ArticlePublished 事件
     * 7. 成功 → 更新文章狀態為 'published'（若尚未為 published）
     */
    public function handle(): void
    {
        Log::info('PublishArticleJob: 開始發佈文章', [
            'article_id' => $this->article->id,
            'platform' => $this->platform,
            'options' => $this->options,
        ]);

        // 1. 建立或取得 PublishRecord，狀態設為 publishing
        $publishRecord = $this->findOrCreatePublishRecord();

        try {
            // 2. 解析對應的 Publisher 實例
            $publisher = $this->resolvePublisher();

            // 3. 執行發佈
            $result = $publisher->publish($this->article, $this->options);

            if ($result->success) {
                // 4a. 成功 → 更新 PublishRecord
                $this->handleSuccess($publishRecord, $result);
            } else {
                // 4b. 失敗 → 更新 PublishRecord 並拋出例外觸發重試
                $this->handleFailure($publishRecord, $result->errorMessage ?? '未知錯誤');
            }
        } catch (\Throwable $e) {
            // 非預期例外 → 更新 PublishRecord 並重新拋出觸發重試
            $errorMessage = $e instanceof \RuntimeException && str_contains($e->getMessage(), 'PublishArticleJob')
                ? $e->getMessage()
                : "發佈過程發生非預期錯誤：{$e->getMessage()}";

            // 僅在非已處理的失敗情況下更新 PublishRecord
            if ($publishRecord->status !== 'failed') {
                $publishRecord->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                ]);
            }

            Log::error('PublishArticleJob: 發佈過程發生例外', [
                'article_id' => $this->article->id,
                'platform' => $this->platform,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 建立或取得此文章 + 平台的 PublishRecord。
     *
     * 若已存在相同文章 + 平台的記錄（非 published 狀態），則重用並更新為 publishing。
     * 否則建立新記錄。
     *
     * @return PublishRecord  發佈記錄
     */
    private function findOrCreatePublishRecord(): PublishRecord
    {
        /** @var PublishRecord $record */
        $record = PublishRecord::updateOrCreate(
            [
                'article_id' => $this->article->id,
                'platform' => $this->platform,
            ],
            [
                'status' => 'publishing',
                'error_message' => null,
            ],
        );

        Log::info('PublishArticleJob: PublishRecord 狀態設為 publishing', [
            'publish_record_id' => $record->id,
            'article_id' => $this->article->id,
            'platform' => $this->platform,
        ]);

        return $record;
    }

    /**
     * 依平台名稱解析對應的 Publisher 實例。
     *
     * 從 Laravel 容器中解析 InkMaginePublisher 或 WordPressPublisher。
     *
     * @return PublisherInterface  發佈器實例
     *
     * @throws \RuntimeException  當平台名稱不支援時
     */
    private function resolvePublisher(): PublisherInterface
    {
        return match ($this->platform) {
            'inkmagine' => app(InkMaginePublisher::class),
            'wordpress' => app(WordPressPublisher::class),
            default => throw new \RuntimeException(
                "PublishArticleJob: 不支援的發佈平台 '{$this->platform}'，支援：inkmagine、wordpress"
            ),
        };
    }

    /**
     * 處理發佈成功。
     *
     * 更新 PublishRecord 狀態為 published（或 draft），記錄 external_id 與 external_url，
     * 觸發 ArticlePublished 事件，並更新文章狀態。
     *
     * @param  PublishRecord  $publishRecord  發佈記錄
     * @param  PublishResult  $result  發佈結果
     */
    private function handleSuccess(PublishRecord $publishRecord, PublishResult $result): void
    {
        $isDraft = ($this->options['status'] ?? 'published') === 'draft';

        // 更新 PublishRecord
        $publishRecord->update([
            'status' => $isDraft ? 'draft' : 'published',
            'external_id' => $result->externalId,
            'external_url' => $result->externalUrl,
            'error_message' => null,
            'published_at' => $isDraft ? null : now(),
        ]);

        Log::info('PublishArticleJob: 文章發佈成功', [
            'article_id' => $this->article->id,
            'platform' => $this->platform,
            'external_id' => $result->externalId,
            'external_url' => $result->externalUrl,
            'is_draft' => $isDraft,
        ]);

        // 觸發 ArticlePublished 事件
        $publishRecord->refresh();
        ArticlePublished::dispatch($this->article, $publishRecord);

        // 更新文章狀態為 published（若非 draft 且尚未為 published）
        if (! $isDraft && $this->article->status !== 'published') {
            $this->article->update([
                'status' => 'published',
                'published_at' => $this->article->published_at ?? now(),
            ]);

            Log::info('PublishArticleJob: 文章狀態更新為 published', [
                'article_id' => $this->article->id,
            ]);
        }
    }

    /**
     * 處理發佈失敗。
     *
     * 更新 PublishRecord 狀態為 failed，記錄錯誤訊息，
     * 並拋出 RuntimeException 觸發佇列重試機制。
     *
     * @param  PublishRecord  $publishRecord  發佈記錄
     * @param  string  $errorMessage  錯誤訊息
     *
     * @throws \RuntimeException  始終拋出以觸發佇列重試
     */
    private function handleFailure(PublishRecord $publishRecord, string $errorMessage): void
    {
        $publishRecord->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);

        Log::warning('PublishArticleJob: 文章發佈失敗', [
            'article_id' => $this->article->id,
            'platform' => $this->platform,
            'error_message' => $errorMessage,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
        ]);

        throw new \RuntimeException(
            "PublishArticleJob: 發佈至 {$this->platform} 失敗 — {$errorMessage}"
        );
    }
}
