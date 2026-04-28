<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\EmbeddingSimilarityService;
use TnlMedia\TrendingSummary\Services\GoogleTrendsService;
use TnlMedia\TrendingSummary\Services\LlmRelevanceService;
use TnlMedia\TrendingSummary\Services\RssFeedService;
use TnlMedia\TrendingSummary\Services\SearchConsoleService;

/**
 * 抓取與篩選命令
 *
 * 僅執行 RSS 抓取與篩選階段，不生成摘要。
 * 流程：RSS 抓取 → 趨勢同步 → Search Console 合併 →
 * Embedding 粗篩 → LLM 精篩。
 */
class FetchAndFilterCommand extends Command
{
    /**
     * 命令簽名。
     *
     * @var string
     */
    protected $signature = 'articles:fetch-and-filter';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '僅執行 RSS 抓取與兩層篩選（不生成摘要）';

    /**
     * 執行命令。
     *
     * 依序執行抓取與篩選階段，並在主控台輸出進度資訊。
     * 任一階段發生錯誤時記錄 log 並輸出錯誤訊息，但不中斷後續階段。
     */
    public function handle(
        RssFeedService $rssFeedService,
        GoogleTrendsService $googleTrendsService,
        SearchConsoleService $searchConsoleService,
        EmbeddingSimilarityService $embeddingSimilarityService,
        LlmRelevanceService $llmRelevanceService,
    ): int {
        $this->info('🚀 開始執行抓取與篩選流程...');
        $this->newLine();

        // 步驟 1：RSS 抓取
        $this->info('📡 步驟 1/5：抓取 RSS feed...');

        try {
            $syncedCount = $rssFeedService->sync();
            $this->info("   ✅ 成功同步 {$syncedCount} 篇新文章");
        } catch (\Throwable $e) {
            $this->error("   ❌ RSS 抓取失敗：{$e->getMessage()}");
            Log::error('FetchAndFilterCommand: RSS 抓取失敗', ['error' => $e->getMessage()]);
        }

        // 步驟 2：趨勢關鍵字同步
        $this->info('📈 步驟 2/5：同步 Google Trends 趨勢關鍵字...');

        try {
            $keywords = $googleTrendsService->sync();
            $keywordCount = count($keywords);
            $this->info("   ✅ 成功同步 {$keywordCount} 個趨勢關鍵字");
        } catch (\Throwable $e) {
            $this->error("   ❌ 趨勢關鍵字同步失敗：{$e->getMessage()}");
            Log::error('FetchAndFilterCommand: 趨勢關鍵字同步失敗', ['error' => $e->getMessage()]);
        }

        // 步驟 3：Search Console 合併
        $this->info('🔍 步驟 3/5：合併 Search Console 查詢詞...');

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
            Log::error('FetchAndFilterCommand: Search Console 合併失敗', ['error' => $e->getMessage()]);
        }

        // 步驟 4：Embedding 粗篩
        $this->info('🧮 步驟 4/5：Embedding 向量相似度粗篩...');

        try {
            $embeddingProcessed = $embeddingSimilarityService->filterPendingArticles();
            $candidateCount = TrendingArticle::where('status', 'candidate')->count();
            $this->info("   ✅ 處理 {$embeddingProcessed} 篇文章，{$candidateCount} 篇通過粗篩");
        } catch (\Throwable $e) {
            $this->error("   ❌ Embedding 粗篩失敗：{$e->getMessage()}");
            Log::error('FetchAndFilterCommand: Embedding 粗篩失敗', ['error' => $e->getMessage()]);
        }

        // 步驟 5：LLM 精篩
        $this->info('🤖 步驟 5/5：LLM 語意精篩...');

        try {
            $llmProcessed = $llmRelevanceService->filterCandidateArticles();
            $filteredCount = TrendingArticle::where('status', 'filtered')->count();
            $this->info("   ✅ 處理 {$llmProcessed} 篇文章，{$filteredCount} 篇通過精篩");
        } catch (\Throwable $e) {
            $this->error("   ❌ LLM 精篩失敗：{$e->getMessage()}");
            Log::error('FetchAndFilterCommand: LLM 精篩失敗', ['error' => $e->getMessage()]);
        }

        $this->newLine();
        $this->info('🏁 抓取與篩選流程執行完畢（未生成摘要）。');

        return self::SUCCESS;
    }
}
