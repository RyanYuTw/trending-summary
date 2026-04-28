<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Events\SummaryGenerated;
use TnlMedia\TrendingSummary\Models\ArticleTemplate;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\ArticleGeneratorService;
use TnlMedia\TrendingSummary\Services\PublishDecisionService;
use TnlMedia\TrendingSummary\Services\QualityReviewService;
use TnlMedia\TrendingSummary\Services\TitleSuggestionService;
use TnlMedia\TrendingSummary\Services\TranslationService;

/**
 * 摘要生成佇列任務
 *
 * 負責執行完整的摘要生成流程：
 * 1. 透過 ArticleGeneratorService 生成結構化繁中摘要
 * 2. 透過 TitleSuggestionService 產出標題候選
 * 3. 若文章內容非繁體中文，透過 TranslationService 翻譯內容
 * 4. 透過 QualityReviewService 執行品質檢查
 * 5. 透過 PublishDecisionService 依運作模式決定後續處理
 * 6. 觸發 SummaryGenerated 事件通知後續流程
 *
 * 採用佇列重試機制：最多重試 3 次，退避間隔為 30、60、120 秒。
 */
class GenerateSummaryJob implements ShouldQueue
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
     * @param TrendingArticle $article 待生成摘要的趨勢文章
     * @param ArticleTemplate|null $template 指定模板，null 時使用預設模板
     */
    public function __construct(
        public readonly TrendingArticle $article,
        public readonly ?ArticleTemplate $template = null,
    ) {}

    /**
     * 執行摘要生成流程。
     *
     * 依序執行：摘要生成 → 標題提案 → 翻譯（如需要） → 品質檢查 → 發佈決策 → 觸發事件。
     * 任一步驟失敗時記錄錯誤並拋出例外，由佇列重試機制處理。
     */
    public function handle(): void
    {
        Log::info('GenerateSummaryJob: 開始執行摘要生成流程', [
            'article_id' => $this->article->id,
            'template_id' => $this->template?->id,
        ]);

        try {
            // 步驟 1：生成結構化摘要
            /** @var ArticleGeneratorService $generatorService */
            $generatorService = app(ArticleGeneratorService::class);
            $article = $generatorService->generate($this->article, $this->template);

            // 步驟 2：產出標題候選
            /** @var TitleSuggestionService $titleService */
            $titleService = app(TitleSuggestionService::class);
            $titleService->generateTitles($article);

            // 步驟 3：若文章內容非繁體中文，翻譯內容
            /** @var TranslationService $translationService */
            $translationService = app(TranslationService::class);

            $contentBody = (string) $article->content_body;
            if ($translationService->isTranslationNeeded($contentBody)) {
                $translatedContent = $translationService->translate($contentBody);
                $article->update(['content_body' => $translatedContent]);

                Log::info('GenerateSummaryJob: 文章內容已翻譯為繁體中文', [
                    'article_id' => $article->id,
                ]);
            }

            // 步驟 4：執行品質檢查
            /** @var QualityReviewService $qualityService */
            $qualityService = app(QualityReviewService::class);
            $qualityService->review($article);

            // 重新載入文章以取得最新的 quality_score
            $article->refresh();

            Log::info('GenerateSummaryJob: 品質檢查完成', [
                'article_id' => $article->id,
                'quality_score' => $article->quality_score,
            ]);

            // 步驟 5：依運作模式決定後續處理（審核 / 自動發佈）
            /** @var PublishDecisionService $publishDecisionService */
            $publishDecisionService = app(PublishDecisionService::class);
            $publishDecisionService->decide($article);

            // 步驟 6：觸發 SummaryGenerated 事件
            SummaryGenerated::dispatch($article);

            Log::info('GenerateSummaryJob: 摘要生成流程完成', [
                'article_id' => $article->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateSummaryJob: 摘要生成流程失敗', [
                'article_id' => $this->article->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
