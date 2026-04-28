<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Jobs\GenerateSummaryJob;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\EmbeddingSimilarityService;
use TnlMedia\TrendingSummary\Services\GoogleTrendsService;
use TnlMedia\TrendingSummary\Services\LlmRelevanceService;
use TnlMedia\TrendingSummary\Services\RssFeedService;
use TnlMedia\TrendingSummary\Services\SearchConsoleService;

/**
 * 趨勢文章完整同步命令
 *
 * 執行完整 pipeline：RSS 抓取 → 趨勢同步 → Search Console 合併 →
 * Embedding 粗篩 → LLM 精篩 → 為每篇通過篩選的文章派發 GenerateSummaryJob。
 */
class SyncTrendingCommand extends Command
{
    /**
     * 命令簽名。
     *
     * @var string
     */
    protected $signature = 'articles:sync-trending';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '執行完整趨勢文章同步 pipeline（RSS 抓取 → 趨勢同步 → 篩選 → 摘要生成）';

    /**
     * 執行命令。
     *
     * 依序執行完整 pipeline 的各個階段，並在主控台輸出進度資訊。
     * 任一階段發生錯誤時記錄 log 並輸出錯誤訊息，但不中斷後續階段。
     */
    public function handle(
        RssFeedService $rssFeedService,
        GoogleTrendsService $googleTrendsService,
        SearchConsoleService $searchConsoleService,
        EmbeddingSimilarityService $embeddingSimilarityService,
        LlmRelevanceService $llmRelevanceService,
    ): int {
        $this->info('🚀 開始執行趨勢文章完整同步 pipeline...');
        $this->newLine();

        // 步驟 1：RSS 抓取
        $this->info('📡 步驟 1/6：抓取 RSS feed...');

        try {
            $syncedCount = $rssFeedService->sync();
            $this->info("   ✅ 成功同步 {$syncedCount} 篇新文章");
        } catch (\Throwable $e) {
            $this->error("   ❌ RSS 抓取失敗：{$e->getMessage()}");
            Log::error('SyncTrendingCommand: RSS 抓取失敗', ['error' => $e->getMessage()]);
        }

        // 步驟 2：趨勢關鍵字同步
        $this->info('📈 步驟 2/6：同步 Google Trends 趨勢關鍵字...');

        try {
            $keywords = $googleTrendsService->sync();
            $keywordCount = count($keywords);
            $this->info("   ✅ 成功同步 {$keywordCount} 個趨勢關鍵字");
        } catch (\Throwable $e) {
            $this->error("   ❌ 趨勢關鍵字同步失敗：{$e->getMessage()}");
            Log::error('SyncTrendingCommand: 趨勢關鍵字同步失敗', ['error' => $e->getMessage()]);
        }

        // 步驟 3：Search Console 合併
        $this->info('🔍 步驟 3/6：合併 Search Console 查詢詞...');

        try {
            $trendKeywords = \TnlMedia\TrendingSummary\Models\TrendKeyword::query()
                ->orderByDesc('trend_date')
                ->orderByDesc('traffic_volume')
                ->pluck('keyword')
                ->all();

            $mergedKeywords = $searchConsoleService->mergeWithTrends($trendKeywords);
            $mergedCount = count($mergedKeywords);

            if ($searchConsoleService->isEnabled()) {
                $this->info("   ✅ 合併後共 {$mergedCount} 個關鍵字（含 Search Console）");
            } else {
                $this->info("   ⏭️  Search Console 未啟用，使用 {$mergedCount} 個趨勢關鍵字");
            }
        } catch (\Throwable $e) {
            $this->error("   ❌ Search Console 合併失敗：{$e->getMessage()}");
            Log::error('SyncTrendingCommand: Search Console 合併失敗', ['error' => $e->getMessage()]);
        }

        // 步驟 4：Embedding 粗篩
        $this->info('🧮 步驟 4/6：Embedding 向量相似度粗篩...');

        try {
            $embeddingProcessed = $embeddingSimilarityService->filterPendingArticles();
            $candidateCount = TrendingArticle::where('status', 'candidate')->count();
            $this->info("   ✅ 處理 {$embeddingProcessed} 篇文章，{$candidateCount} 篇通過粗篩");
        } catch (\Throwable $e) {
            $this->error("   ❌ Embedding 粗篩失敗：{$e->getMessage()}");
            Log::error('SyncTrendingCommand: Embedding 粗篩失敗', ['error' => $e->getMessage()]);
        }

        // 步驟 5：LLM 精篩
        $this->info('🤖 步驟 5/6：LLM 語意精篩...');

        try {
            $llmProcessed = $llmRelevanceService->filterCandidateArticles();
            $filteredCount = TrendingArticle::where('status', 'filtered')->count();
            $this->info("   ✅ 處理 {$llmProcessed} 篇文章，{$filteredCount} 篇通過精篩");
        } catch (\Throwable $e) {
            $this->error("   ❌ LLM 精篩失敗：{$e->getMessage()}");
            Log::error('SyncTrendingCommand: LLM 精篩失敗', ['error' => $e->getMessage()]);
        }

        // 步驟 6：為通過篩選的文章派發摘要生成 Job
        $this->info('📝 步驟 6/6：派發摘要生成任務...');

        try {
            $newlyFiltered = TrendingArticle::where('status', 'filtered')
                ->whereNull('summary')
                ->get();

            $dispatchedCount = 0;

            foreach ($newlyFiltered as $article) {
                GenerateSummaryJob::dispatch($article);
                $dispatchedCount++;
            }

            $this->info("   ✅ 已派發 {$dispatchedCount} 個 GenerateSummaryJob");
        } catch (\Throwable $e) {
            $this->error("   ❌ 派發摘要生成任務失敗：{$e->getMessage()}");
            Log::error('SyncTrendingCommand: 派發摘要生成任務失敗', ['error' => $e->getMessage()]);
        }

        $this->newLine();
        $this->info('🏁 趨勢文章完整同步 pipeline 執行完畢。');

        return self::SUCCESS;
    }
}
