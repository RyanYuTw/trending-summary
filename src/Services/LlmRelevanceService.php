<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Contracts\LlmInterface;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Models\TrendKeyword;

/**
 * LLM 語意精篩服務
 *
 * 負責以 LLM 語意判斷文章與趨勢的相關性（第二層精篩）。
 * 透過 AiModelManagerInterface 取得 sub-role 為 relevance 的 LLM driver，
 * 使用 generateStructured() 評估 candidate 文章與趨勢關鍵字的語意相關性。
 * 通過 → status = filtered + 記錄 relevance_score；
 * 未通過 → status = rejected（LLM stage）；
 * API 失敗 → 保留 candidate 狀態待重試。
 */
class LlmRelevanceService
{
    /**
     * 文章內容截斷長度（字元數），避免超出 token 限制。
     */
    private const int MAX_CONTENT_LENGTH = 2000;

    /**
     * 建構子，注入 AiModelManagerInterface。
     *
     * @param AiModelManagerInterface $aiManager AI 模型管理器
     */
    public function __construct(
        protected AiModelManagerInterface $aiManager,
    ) {}

    /**
     * 篩選所有 candidate 狀態的文章。
     *
     * 主要入口方法。取得所有 candidate 文章與當前趨勢關鍵字，
     * 逐篇透過 LLM 評估語意相關性，依結果更新文章狀態。
     *
     * @return int 處理的文章數量
     */
    public function filterCandidateArticles(): int
    {
        $keywords = TrendKeyword::query()
            ->orderByDesc('trend_date')
            ->orderByDesc('traffic_volume')
            ->pluck('keyword')
            ->all();

        if ($keywords === []) {
            Log::warning('LlmRelevanceService: 無可用的趨勢關鍵字，跳過篩選');

            return 0;
        }

        $articles = TrendingArticle::where('status', 'candidate')->get();
        $processed = 0;

        foreach ($articles as $article) {
            try {
                $this->evaluateArticle($article, $keywords);
                $processed++;
            } catch (\Throwable $e) {
                Log::error('LlmRelevanceService: 文章評估失敗，保留 candidate 狀態待重試', [
                    'article_id' => $article->id,
                    'error' => $e->getMessage(),
                ]);
                // API 失敗時保留 candidate 狀態，不更新文章
            }
        }

        return $processed;
    }

    /**
     * 評估單一文章與趨勢關鍵字的語意相關性。
     *
     * 透過 LLM（sub-role: relevance）的 generateStructured() 方法，
     * 取得結構化的相關性評估結果。通過 → status = filtered + 記錄 relevance_score；
     * 未通過 → status = rejected。API 失敗時拋出例外由呼叫端處理。
     *
     * @param TrendingArticle $article 待評估的文章
     * @param array<int, string> $keywords 趨勢關鍵字陣列
     *
     * @throws \Throwable 當 LLM API 呼叫失敗時
     */
    public function evaluateArticle(TrendingArticle $article, array $keywords): void
    {
        $prompt = $this->buildRelevancePrompt($article, $keywords);
        $schema = $this->getRelevanceSchema();

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'relevance');

        $result = $llm->generateStructured($prompt, $schema);

        $isRelevant = (bool) ($result['is_relevant'] ?? false);
        $relevanceScore = (float) ($result['relevance_score'] ?? 0.0);
        $reasoning = (string) ($result['reasoning'] ?? '');

        // 確保 relevance_score 在有效範圍內
        $relevanceScore = max(0.0, min(1.0, $relevanceScore));

        if ($isRelevant) {
            $article->update([
                'status' => 'filtered',
                'relevance_score' => $relevanceScore,
            ]);

            Log::info('LlmRelevanceService: 文章通過 LLM 精篩', [
                'article_id' => $article->id,
                'relevance_score' => $relevanceScore,
                'reasoning' => $reasoning,
            ]);
        } else {
            $article->update([
                'status' => 'rejected',
                'relevance_score' => $relevanceScore,
            ]);

            Log::info('LlmRelevanceService: 文章未通過 LLM 精篩', [
                'article_id' => $article->id,
                'relevance_score' => $relevanceScore,
                'reasoning' => $reasoning,
            ]);
        }
    }

    /**
     * 建構 LLM 相關性評估的提示詞。
     *
     * 包含文章標題、截斷後的內容、以及趨勢關鍵字列表，
     * 要求 LLM 評估文章與趨勢的語意相關性。
     *
     * @param TrendingArticle $article 待評估的文章
     * @param array<int, string> $keywords 趨勢關鍵字陣列
     * @return string 完整的提示詞
     */
    public function buildRelevancePrompt(TrendingArticle $article, array $keywords): string
    {
        $title = trim((string) $article->title);
        $content = $this->truncateContent((string) $article->content_body);
        $keywordList = implode('、', $keywords);

        return <<<PROMPT
你是一位專業的內容相關性評估專家。請評估以下文章與當前趨勢關鍵字的語意相關性。

## 文章標題
{$title}

## 文章內容
{$content}

## 當前趨勢關鍵字
{$keywordList}

## 評估標準
1. 文章主題是否與至少一個趨勢關鍵字直接相關
2. 文章內容是否提供了與趨勢相關的有價值資訊
3. 文章是否適合改寫為趨勢摘要文章

## 回應要求
請以 JSON 格式回應，包含以下欄位：
- is_relevant: boolean — 文章是否與趨勢相關
- relevance_score: float (0.0-1.0) — 相關性分數，越高越相關
- reasoning: string — 簡短說明評估理由
PROMPT;
    }

    /**
     * 取得 LLM 結構化輸出的 JSON Schema。
     *
     * 定義 generateStructured() 期望的回應結構，
     * 包含 is_relevant（布林）、relevance_score（浮點數）、reasoning（字串）。
     *
     * @return array<string, mixed> JSON Schema 定義
     */
    public function getRelevanceSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_relevant' => [
                    'type' => 'boolean',
                    'description' => '文章是否與趨勢關鍵字相關',
                ],
                'relevance_score' => [
                    'type' => 'number',
                    'minimum' => 0.0,
                    'maximum' => 1.0,
                    'description' => '相關性分數，0.0 表示完全不相關，1.0 表示高度相關',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => '評估理由的簡短說明',
                ],
            ],
            'required' => ['is_relevant', 'relevance_score', 'reasoning'],
        ];
    }

    /**
     * 截斷文章內容至合理長度。
     *
     * 避免超出 LLM token 限制，截斷至 MAX_CONTENT_LENGTH 字元。
     * 若內容被截斷，會附加省略號標記。
     *
     * @param string $content 原始文章內容
     * @return string 截斷後的內容
     */
    private function truncateContent(string $content): string
    {
        $content = trim($content);

        if ($content === '') {
            return '（無內容）';
        }

        if (mb_strlen($content) <= self::MAX_CONTENT_LENGTH) {
            return $content;
        }

        return mb_substr($content, 0, self::MAX_CONTENT_LENGTH) . '…（內容已截斷）';
    }
}
