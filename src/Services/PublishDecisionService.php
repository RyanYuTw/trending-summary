<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Jobs\PublishArticleJob;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * 發佈決策服務
 *
 * 負責在品質檢查完成後，依系統運作模式（審核模式 / 自動模式）
 * 決定文章的後續處理流程。
 *
 * - 審核模式（Review_Mode）：文章狀態設為 reviewing，等待人工審核。
 * - 自動模式（Auto_Mode）：
 *   - 品質分數 ≥ 門檻 → 自動選擇最高分 SEO 標題、設為 approved、
 *     標記 is_auto_published、dispatch PublishArticleJob、記錄審計日誌。
 *   - 品質分數 < 門檻 → 同審核模式，設為 reviewing 等待人工審核。
 *
 * 模式切換透過 config 即時生效，無需重啟。
 */
class PublishDecisionService
{
    /**
     * 依運作模式決定文章的後續處理（主方法）。
     *
     * 品質檢查完成後呼叫此方法，依 config 判斷運作模式並執行對應邏輯。
     * Review_Mode 下一律進入人工審核；Auto_Mode 下依品質分數決定自動發佈或人工審核。
     *
     * @param  TrendingArticle  $article  已完成品質檢查的文章（應已有 quality_score）
     */
    public function decide(TrendingArticle $article): void
    {
        if ($this->isAutoMode()) {
            $this->handleAutoMode($article);
        } else {
            $this->handleReviewMode($article);
        }
    }

    /**
     * 檢查系統是否為自動模式。
     *
     * 從 config 即時讀取運作模式，模式切換立即生效。
     *
     * @return bool  true 表示自動模式，false 表示審核模式
     */
    public function isAutoMode(): bool
    {
        return config('trending-summary.mode', 'review') === 'auto';
    }

    /**
     * 取得自動發佈的品質分數門檻。
     *
     * @return int  品質分數門檻（預設 80，範圍 0-100）
     */
    public function getAutoPublishThreshold(): int
    {
        return (int) config('trending-summary.auto_publish_threshold', 80);
    }

    /**
     * 自動選擇最高分 SEO 風格標題。
     *
     * 在自動模式下，從文章的 AI 生成標題候選中選擇 SEO 風格的標題。
     * 若無 SEO 風格標題，則選擇第一個可用標題。
     * 選中的標題會標記 is_selected 並更新文章的 selected_title。
     *
     * @param  TrendingArticle  $article  待選擇標題的文章
     */
    public function autoSelectTitle(TrendingArticle $article): void
    {
        // 先取消所有已選標題
        $article->generatedTitles()->update(['is_selected' => false]);

        // 優先選擇 SEO 風格標題
        $seoTitle = $article->generatedTitles()
            ->where('style', 'seo')
            ->first();

        // 若無 SEO 風格標題，選擇第一個可用標題
        $selectedTitle = $seoTitle ?? $article->generatedTitles()->first();

        if ($selectedTitle !== null) {
            $selectedTitle->update(['is_selected' => true]);
            $article->update(['selected_title' => $selectedTitle->text]);

            Log::info('PublishDecisionService: 自動選擇 SEO 風格標題', [
                'article_id' => $article->id,
                'title_id' => $selectedTitle->id,
                'style' => $selectedTitle->style,
                'title_text' => $selectedTitle->text,
            ]);
        }
    }

    /**
     * 記錄自動發佈的審計日誌。
     *
     * 記錄文章的品質分數、自動發佈門檻、選定標題等資訊，
     * 提供完整的審計追蹤。
     *
     * @param  TrendingArticle  $article  已自動發佈的文章
     */
    public function logAutoPublish(TrendingArticle $article): void
    {
        Log::channel(config('logging.default'))
            ->info('PublishDecisionService: 文章自動發佈', [
                'article_id' => $article->id,
                'uuid' => $article->uuid,
                'title' => $article->selected_title ?? $article->title,
                'quality_score' => $article->quality_score,
                'auto_publish_threshold' => $this->getAutoPublishThreshold(),
                'reason' => sprintf(
                    '品質分數 %d 達到自動發佈門檻 %d，自動核准發佈',
                    $article->quality_score ?? 0,
                    $this->getAutoPublishThreshold(),
                ),
                'is_auto_published' => true,
                'mode' => 'auto',
                'timestamp' => now()->toIso8601String(),
            ]);
    }

    // ──────────────────────────────────────────────────────────
    // 私有輔助方法
    // ──────────────────────────────────────────────────────────

    /**
     * 處理審核模式邏輯。
     *
     * 將文章狀態設為 reviewing，等待人工審核。
     *
     * @param  TrendingArticle  $article  文章
     */
    private function handleReviewMode(TrendingArticle $article): void
    {
        $article->update(['status' => 'reviewing']);

        Log::info('PublishDecisionService: 審核模式 — 文章進入人工審核', [
            'article_id' => $article->id,
            'quality_score' => $article->quality_score,
        ]);
    }

    /**
     * 處理自動模式邏輯。
     *
     * 依品質分數判斷：
     * - 分數 ≥ 門檻 → 自動選擇標題、核准、dispatch 發佈任務、記錄審計日誌
     * - 分數 < 門檻 → 同審核模式，進入人工審核
     *
     * @param  TrendingArticle  $article  文章
     */
    private function handleAutoMode(TrendingArticle $article): void
    {
        $qualityScore = $article->quality_score ?? 0;
        $threshold = $this->getAutoPublishThreshold();

        if ($qualityScore >= $threshold) {
            $this->approveAndPublish($article);
        } else {
            // 品質分數未達門檻，進入人工審核
            $article->update(['status' => 'reviewing']);

            Log::info('PublishDecisionService: 自動模式 — 品質分數未達門檻，進入人工審核', [
                'article_id' => $article->id,
                'quality_score' => $qualityScore,
                'threshold' => $threshold,
            ]);
        }
    }

    /**
     * 核准文章並 dispatch 發佈任務。
     *
     * 執行自動發佈的完整流程：
     * 1. 自動選擇最高分 SEO 風格標題
     * 2. 設定文章狀態為 approved
     * 3. 標記 is_auto_published = true
     * 4. 對每個預設發佈平台 dispatch PublishArticleJob
     * 5. 記錄審計日誌
     *
     * @param  TrendingArticle  $article  文章
     */
    private function approveAndPublish(TrendingArticle $article): void
    {
        // 1. 自動選擇 SEO 風格標題
        $this->autoSelectTitle($article);

        // 2. 更新狀態為 approved 並標記自動發佈
        $article->update([
            'status' => 'approved',
            'is_auto_published' => true,
        ]);

        // 3. 對預設發佈平台 dispatch PublishArticleJob
        /** @var array<int, string> $defaultPlatforms */
        $defaultPlatforms = config('trending-summary.publishing.default_targets', ['inkmagine']);

        foreach ($defaultPlatforms as $platform) {
            PublishArticleJob::dispatch($article, $platform);

            Log::info('PublishDecisionService: 已 dispatch 發佈任務', [
                'article_id' => $article->id,
                'platform' => $platform,
            ]);
        }

        // 4. 記錄審計日誌
        $this->logAutoPublish($article);
    }
}
