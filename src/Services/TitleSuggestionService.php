<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Contracts\LlmInterface;
use TnlMedia\TrendingSummary\Models\GeneratedTitle;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Models\TrendKeyword;

/**
 * 標題候選生成服務
 *
 * 負責根據文章內容與趨勢關鍵字，透過 LLM（sub-role: title）產出 3-5 組標題候選。
 * 涵蓋三種風格：news（新聞風格）、social（社群風格）、seo（SEO 優化風格），
 * 並自然融入趨勢關鍵字。生成的標題儲存為 GeneratedTitle 記錄，
 * 支援重新生成（先刪除既有標題再建立新標題）。
 */
class TitleSuggestionService
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
     * 為文章生成 3-5 組標題候選。
     *
     * 透過 LLM（sub-role: title）的 generateStructured() 方法產出標題候選，
     * 涵蓋 news、social、seo 三種風格並融入趨勢關鍵字。
     * 生成前會先刪除該文章既有的 GeneratedTitle 記錄（支援重新生成），
     * 再將新標題逐筆儲存為 GeneratedTitle（is_selected = false）。
     *
     * @param TrendingArticle $article 待生成標題的文章（應已有摘要）
     * @return array<int, GeneratedTitle> 生成的標題候選陣列
     *
     * @throws \Throwable 當 LLM API 呼叫失敗時
     */
    public function generateTitles(TrendingArticle $article): array
    {
        try {
            $prompt = $this->buildTitlePrompt($article);
            $schema = $this->getTitleSchema();

            /** @var LlmInterface $llm */
            $llm = $this->aiManager->driver('llm', 'title');

            $result = $llm->generateStructured($prompt, $schema);

            /** @var array<int, array{text: string, style: string}> $titles */
            $titles = $result['titles'] ?? [];

            // 刪除該文章既有的標題候選（支援重新生成）
            $article->generatedTitles()->delete();

            $generatedTitles = [];

            foreach ($titles as $titleData) {
                $text = trim((string) ($titleData['text'] ?? ''));
                $style = (string) ($titleData['style'] ?? '');

                // 跳過空標題或無效風格
                if ($text === '' || ! in_array($style, ['news', 'social', 'seo'], true)) {
                    continue;
                }

                $generatedTitle = GeneratedTitle::create([
                    'article_id' => $article->id,
                    'text' => $text,
                    'style' => $style,
                    'is_selected' => false,
                ]);

                $generatedTitles[] = $generatedTitle;
            }

            Log::info('TitleSuggestionService: 標題候選生成完成', [
                'article_id' => $article->id,
                'count' => count($generatedTitles),
                'styles' => array_map(fn (GeneratedTitle $t) => $t->style, $generatedTitles),
            ]);

            return $generatedTitles;
        } catch (\Throwable $e) {
            Log::error('TitleSuggestionService: 標題候選生成失敗', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 建構 LLM 標題生成的提示詞。
     *
     * 包含文章標題、截斷後的摘要或內容、以及趨勢關鍵字列表，
     * 要求 LLM 產出 3-5 組涵蓋 news、social、seo 三種風格的標題候選。
     *
     * @param TrendingArticle $article 待生成標題的文章
     * @return string 完整的提示詞
     */
    public function buildTitlePrompt(TrendingArticle $article): string
    {
        $title = trim((string) $article->title);
        $content = $this->resolveArticleContent($article);

        // 取得趨勢關鍵字：優先使用文章已匹配的關鍵字，否則取最新趨勢
        $trendKeywords = $this->resolveTrendKeywords($article);
        $keywordSection = '';
        if ($trendKeywords !== []) {
            $keywordList = implode('、', $trendKeywords);
            $keywordSection = <<<SECTION

## 趨勢關鍵字
{$keywordList}
請在標題中自然融入上述趨勢關鍵字，提升搜尋能見度。
SECTION;
        }

        return <<<PROMPT
你是一位專業的繁體中文標題撰寫專家。請根據以下文章內容，產出 3 到 5 組標題候選，涵蓋三種風格。

## 原始文章標題
{$title}

## 文章內容摘要
{$content}
{$keywordSection}

## 標題風格要求
請產出以下三種風格的標題，每種風格至少一組：

1. **news**（新聞風格）：客觀、正式、簡潔，適合新聞媒體使用。
2. **social**（社群風格）：吸引眼球、口語化、帶有情緒或好奇心，適合社群媒體分享。
3. **seo**（SEO 優化風格）：包含主要關鍵字、結構清晰、適合搜尋引擎排名。

## 注意事項
- 每組標題使用繁體中文
- 標題長度建議 15-40 字元
- 總共產出 3 到 5 組標題
- 三種風格各至少一組
- 標題應準確反映文章內容，不可捏造資訊

## 回應要求
請以 JSON 格式回應，包含 titles 陣列，每個元素含 text（標題文字）與 style（風格：news、social、seo）。
PROMPT;
    }

    /**
     * 取得 LLM 結構化輸出的 JSON Schema。
     *
     * 定義 generateStructured() 期望的回應結構，
     * 包含 titles 陣列，每個元素含 text（字串）與 style（列舉：news、social、seo）。
     *
     * @return array<string, mixed> JSON Schema 定義
     */
    public function getTitleSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'titles' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => [
                                'type' => 'string',
                                'description' => '標題文字',
                            ],
                            'style' => [
                                'type' => 'string',
                                'enum' => ['news', 'social', 'seo'],
                                'description' => '標題風格：news（新聞）、social（社群）、seo（SEO 優化）',
                            ],
                        ],
                        'required' => ['text', 'style'],
                    ],
                    'minItems' => 3,
                    'maxItems' => 5,
                    'description' => '3 到 5 組標題候選',
                ],
            ],
            'required' => ['titles'],
        ];
    }

    /**
     * 解析文章內容用於提示詞。
     *
     * 優先使用文章摘要（summary），若無摘要則使用原始內容（content_body），
     * 並截斷至合理長度以避免超出 token 限制。
     *
     * @param TrendingArticle $article 文章
     * @return string 截斷後的文章內容
     */
    private function resolveArticleContent(TrendingArticle $article): string
    {
        // 優先使用摘要
        $content = trim((string) $article->summary);

        if ($content === '') {
            $content = trim((string) $article->content_body);
        }

        if ($content === '') {
            return '（無內容）';
        }

        if (mb_strlen($content) <= self::MAX_CONTENT_LENGTH) {
            return $content;
        }

        return mb_substr($content, 0, self::MAX_CONTENT_LENGTH) . '…（內容已截斷）';
    }

    /**
     * 解析趨勢關鍵字。
     *
     * 優先使用文章已匹配的趨勢關鍵字（trend_keywords 欄位），
     * 若無則從 TrendKeyword 表取得最新的趨勢關鍵字（依流量排序，取前 10 筆）。
     *
     * @param TrendingArticle $article 文章
     * @return array<int, string> 趨勢關鍵字陣列
     */
    private function resolveTrendKeywords(TrendingArticle $article): array
    {
        // 優先使用文章已匹配的趨勢關鍵字
        $articleKeywords = $article->trend_keywords;
        if (is_array($articleKeywords) && $articleKeywords !== []) {
            return $articleKeywords;
        }

        // Fallback：取最新趨勢關鍵字
        return TrendKeyword::query()
            ->orderByDesc('trend_date')
            ->orderByDesc('traffic_volume')
            ->limit(10)
            ->pluck('keyword')
            ->all();
    }
}
