<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Contracts\LlmInterface;
use TnlMedia\TrendingSummary\Models\QualityReport;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * 品質檢查服務
 *
 * 負責對 AI 生成的繁體中文摘要執行全面品質檢查，包含拼寫與文法檢查、
 * 專有名詞翻譯一致性、敏感用語偵測、事實查核輔助（連結至原文段落）、
 * SEO 改善建議。當 AIO 優化啟用時，額外驗證 AIO 檢查清單項目。
 *
 * 透過 AiModelManagerInterface 取得 quality 子角色的 LLM driver，
 * 使用單次 generateStructured() 呼叫一次完成所有品質檢查（降低 API 成本）。
 * 依各類問題數量與嚴重度計算 overall_score（0-100），
 * 並將結果編譯為 QualityReport 物件儲存。同時更新文章的 quality_score 欄位。
 */
class QualityReviewService
{
    /**
     * 文章內容截斷長度（字元數），避免超出 token 限制。
     */
    private const int MAX_CONTENT_LENGTH = 3000;

    /**
     * 各類問題的扣分權重。
     */
    private const int PENALTY_SPELLING = 3;
    private const int PENALTY_TERMINOLOGY = 5;
    private const int PENALTY_SENSITIVE = 8;
    private const int PENALTY_FACT_REFERENCE = 2;
    private const int PENALTY_SEO_SUGGESTION = 2;
    private const int PENALTY_AIO_FAILURE = 5;

    /**
     * 建構子，注入 AiModelManagerInterface。
     *
     * @param AiModelManagerInterface $aiManager AI 模型管理器
     */
    public function __construct(
        protected AiModelManagerInterface $aiManager,
    ) {}

    /**
     * 對文章執行完整品質檢查（主方法）。
     *
     * 透過 LLM（sub-role: quality）一次性執行所有品質檢查，
     * 包含拼寫、用語一致性、敏感用語、事實參照、SEO 建議。
     * 當 AIO 啟用時額外驗證 AIO 檢查清單。
     * 計算 overall_score 後儲存為 QualityReport，並更新文章的 quality_score。
     *
     * @param  TrendingArticle  $article  待檢查的文章（應已有摘要）
     * @return QualityReport  品質檢查報告
     *
     * @throws \Throwable  當 LLM API 呼叫失敗時
     */
    public function review(TrendingArticle $article): QualityReport
    {
        try {
            $summaryText = trim((string) $article->summary);
            $contentBody = trim((string) $article->content_body);

            // 透過 LLM 一次性執行所有品質檢查
            $llmResult = $this->performLlmQualityCheck($article, $summaryText, $contentBody);

            $spellingIssues = $this->checkSpelling($summaryText, $llmResult);
            $terminologyIssues = $this->checkTerminology($summaryText, $llmResult);
            $sensitiveTerms = $this->checkSensitiveTerms($summaryText, $llmResult);
            $factReferences = $this->checkFactReferences($article, $llmResult);
            $seoSuggestions = $this->generateSeoSuggestions($article, $llmResult);

            // AIO 檢查清單
            $aioChecklist = $this->verifyAioChecklist($article, $llmResult);

            // 組裝報告資料
            $reportData = [
                'spelling_issues' => $spellingIssues,
                'terminology_issues' => $terminologyIssues,
                'sensitive_terms' => $sensitiveTerms,
                'fact_references' => $factReferences,
                'seo_suggestions' => $seoSuggestions,
                'aio_checklist' => $aioChecklist,
            ];

            // 計算總分
            $overallScore = $this->calculateOverallScore($reportData);
            $reportData['overall_score'] = $overallScore;

            // 儲存品質報告
            $qualityReport = QualityReport::updateOrCreate(
                ['article_id' => $article->id],
                array_merge(['article_id' => $article->id], $reportData),
            );

            // 更新文章的 quality_score
            $article->update(['quality_score' => $overallScore]);

            Log::info('QualityReviewService: 品質檢查完成', [
                'article_id' => $article->id,
                'overall_score' => $overallScore,
                'spelling_count' => count($spellingIssues),
                'terminology_count' => count($terminologyIssues),
                'sensitive_count' => count($sensitiveTerms),
            ]);

            return $qualityReport->refresh();
        } catch (\Throwable $e) {
            Log::error('QualityReviewService: 品質檢查失敗', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 檢查拼寫與文法問題。
     *
     * 從 LLM 品質檢查結果中提取拼寫與文法問題清單。
     * 每個問題包含 text（問題文字）、suggestion（建議修正）、position（位置描述）。
     *
     * @param  string  $text  摘要文字
     * @param  array<string, mixed>  $llmResult  LLM 品質檢查結果
     * @return array<int, array{text: string, suggestion: string, position: string}>  拼寫問題清單
     */
    public function checkSpelling(string $text, array $llmResult = []): array
    {
        /** @var array<int, array{text: string, suggestion: string, position: string}> $issues */
        $issues = $llmResult['spelling_issues'] ?? [];

        return array_values(array_filter(
            $issues,
            fn (array $item): bool => trim((string) ($item['text'] ?? '')) !== '',
        ));
    }

    /**
     * 檢查專有名詞翻譯一致性。
     *
     * 從 LLM 品質檢查結果中提取用語不一致的問題清單。
     * 每個問題包含 term（原文詞彙）、variants（出現的不同翻譯版本）、
     * suggested（建議統一使用的翻譯）。
     *
     * @param  string  $text  摘要文字
     * @param  array<string, mixed>  $llmResult  LLM 品質檢查結果
     * @return array<int, array{term: string, variants: array<int, string>, suggested: string}>  用語問題清單
     */
    public function checkTerminology(string $text, array $llmResult = []): array
    {
        /** @var array<int, array{term: string, variants: array<int, string>, suggested: string}> $issues */
        $issues = $llmResult['terminology_issues'] ?? [];

        return array_values(array_filter(
            $issues,
            fn (array $item): bool => trim((string) ($item['term'] ?? '')) !== '',
        ));
    }

    /**
     * 檢查敏感或爭議性用語。
     *
     * 從 LLM 品質檢查結果中提取敏感用語清單。
     * 每個項目包含 term（敏感詞彙）、reason（敏感原因）、alternative（建議替代用語）。
     *
     * @param  string  $text  摘要文字
     * @param  array<string, mixed>  $llmResult  LLM 品質檢查結果
     * @return array<int, array{term: string, reason: string, alternative: string}>  敏感用語清單
     */
    public function checkSensitiveTerms(string $text, array $llmResult = []): array
    {
        /** @var array<int, array{term: string, reason: string, alternative: string}> $issues */
        $issues = $llmResult['sensitive_terms'] ?? [];

        return array_values(array_filter(
            $issues,
            fn (array $item): bool => trim((string) ($item['term'] ?? '')) !== '',
        ));
    }

    /**
     * 檢查事實參照（連結至原文段落）。
     *
     * 從 LLM 品質檢查結果中提取事實聲明清單，每個聲明連結至原文對應段落。
     * 每個項目包含 claim（摘要中的事實聲明）、original_passage（原文對應段落）、
     * verified（是否可在原文中找到對應）。
     *
     * @param  TrendingArticle  $article  文章
     * @param  array<string, mixed>  $llmResult  LLM 品質檢查結果
     * @return array<int, array{claim: string, original_passage: string, verified: bool}>  事實參照清單
     */
    public function checkFactReferences(TrendingArticle $article, array $llmResult = []): array
    {
        /** @var array<int, array{claim: string, original_passage: string, verified: bool}> $references */
        $references = $llmResult['fact_references'] ?? [];

        return array_values(array_filter(
            $references,
            fn (array $item): bool => trim((string) ($item['claim'] ?? '')) !== '',
        ));
    }

    /**
     * 產生 SEO 改善建議。
     *
     * 從 LLM 品質檢查結果中提取 SEO 改善建議清單。
     * 每個建議包含 category（類別）、issue（問題描述）、suggestion（改善建議）。
     *
     * @param  TrendingArticle  $article  文章
     * @param  array<string, mixed>  $llmResult  LLM 品質檢查結果
     * @return array<int, array{category: string, issue: string, suggestion: string}>  SEO 建議清單
     */
    public function generateSeoSuggestions(TrendingArticle $article, array $llmResult = []): array
    {
        /** @var array<int, array{category: string, issue: string, suggestion: string}> $suggestions */
        $suggestions = $llmResult['seo_suggestions'] ?? [];

        return array_values(array_filter(
            $suggestions,
            fn (array $item): bool => trim((string) ($item['issue'] ?? '')) !== '',
        ));
    }

    /**
     * 驗證 AIO 檢查清單。
     *
     * 當 AIO 優化啟用時，驗證以下項目：
     * - Direct Answer Block 是否存在
     * - 問句式標題比例是否達標
     * - 可掃描格式是否包含
     * - FAQ 是否存在
     * - FAQPage Schema 是否有效
     * - 焦點關鍵字密度是否合理
     * - 內容長度是否達標
     * - 來源引用是否存在
     *
     * @param  TrendingArticle  $article  文章
     * @param  array<string, mixed>  $llmResult  LLM 品質檢查結果
     * @return array<string, bool>  AIO 檢查清單（各項目通過/未通過）
     */
    public function verifyAioChecklist(TrendingArticle $article, array $llmResult = []): array
    {
        $aioEnabled = (bool) config('trending-summary.seo.aio.enabled', true);

        if (! $aioEnabled) {
            return [];
        }

        $seo = $article->seo;
        $summary = trim((string) $article->summary);
        $minContentLength = (int) config('trending-summary.seo.aio.min_content_length', 300);

        // Direct Answer Block 存在性
        $hasDirectAnswerBlock = $seo !== null
            && trim((string) $seo->direct_answer_block) !== '';

        // FAQ 存在性
        $hasFaq = $seo !== null
            && is_array($seo->faq_items)
            && count($seo->faq_items) > 0;

        // FAQPage Schema 有效性
        $hasFaqSchema = $seo !== null
            && is_array($seo->faq_schema)
            && ($seo->faq_schema['@type'] ?? '') === 'FAQPage'
            && ! empty($seo->faq_schema['mainEntity']);

        // 內容長度
        $meetsContentLength = mb_strlen($summary) >= $minContentLength;

        // 從 LLM 結果取得額外 AIO 檢查
        $llmAioChecklist = $llmResult['aio_checklist'] ?? [];

        // 問句式標題比例（從 LLM 結果或 SEO 資料取得）
        $hasQuestionHeadings = (bool) ($llmAioChecklist['question_headings'] ?? false);

        // 可掃描格式
        $hasScannableFormats = (bool) ($llmAioChecklist['scannable_formats'] ?? false);

        // 焦點關鍵字密度
        $hasKeywordDensity = (bool) ($llmAioChecklist['keyword_density'] ?? false);

        // 來源引用
        $hasSourceCitation = (bool) ($llmAioChecklist['source_citation'] ?? false);

        return [
            'direct_answer_block' => $hasDirectAnswerBlock,
            'question_headings' => $hasQuestionHeadings,
            'scannable_formats' => $hasScannableFormats,
            'faq_present' => $hasFaq,
            'faq_schema_valid' => $hasFaqSchema,
            'keyword_density' => $hasKeywordDensity,
            'content_length' => $meetsContentLength,
            'source_citation' => $hasSourceCitation,
        ];
    }

    /**
     * 計算品質總分（0-100）。
     *
     * 從滿分 100 開始，依各類問題數量扣分：
     * - spelling_issues：每個 -3 分
     * - terminology_issues：每個 -5 分
     * - sensitive_terms：每個 -8 分
     * - fact_references 中未驗證的：每個 -2 分
     * - seo_suggestions：每個 -2 分
     * - aio_checklist 中未通過的：每個 -5 分
     * 最低分為 0。
     *
     * @param  array<string, mixed>  $report  品質報告資料
     * @return int  品質總分（0-100）
     */
    public function calculateOverallScore(array $report): int
    {
        $score = 100;

        // 拼寫問題扣分
        $spellingCount = count($report['spelling_issues'] ?? []);
        $score -= $spellingCount * self::PENALTY_SPELLING;

        // 用語問題扣分
        $terminologyCount = count($report['terminology_issues'] ?? []);
        $score -= $terminologyCount * self::PENALTY_TERMINOLOGY;

        // 敏感用語扣分
        $sensitiveCount = count($report['sensitive_terms'] ?? []);
        $score -= $sensitiveCount * self::PENALTY_SENSITIVE;

        // 事實參照：未驗證的扣分
        $factReferences = $report['fact_references'] ?? [];
        $unverifiedCount = count(array_filter(
            $factReferences,
            fn (array $ref): bool => ! ($ref['verified'] ?? true),
        ));
        $score -= $unverifiedCount * self::PENALTY_FACT_REFERENCE;

        // SEO 建議扣分
        $seoCount = count($report['seo_suggestions'] ?? []);
        $score -= $seoCount * self::PENALTY_SEO_SUGGESTION;

        // AIO 檢查清單：未通過的項目扣分
        $aioChecklist = $report['aio_checklist'] ?? [];
        if (is_array($aioChecklist) && $aioChecklist !== []) {
            $failedAioCount = count(array_filter(
                $aioChecklist,
                fn (bool $passed): bool => ! $passed,
            ));
            $score -= $failedAioCount * self::PENALTY_AIO_FAILURE;
        }

        return max(0, $score);
    }

    // ──────────────────────────────────────────────────────────
    // 私有輔助方法
    // ──────────────────────────────────────────────────────────

    /**
     * 透過 LLM 執行一次性品質檢查。
     *
     * 使用 quality 子角色的 LLM，透過單次 generateStructured() 呼叫
     * 同時執行所有品質檢查項目，降低 API 呼叫次數與成本。
     *
     * @param  TrendingArticle  $article  文章
     * @param  string  $summaryText  摘要文字
     * @param  string  $contentBody  原始內容
     * @return array<string, mixed>  LLM 品質檢查結構化結果
     */
    private function performLlmQualityCheck(
        TrendingArticle $article,
        string $summaryText,
        string $contentBody,
    ): array {
        if ($summaryText === '') {
            return [];
        }

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'quality');

        $truncatedContent = $this->truncateContent($contentBody);
        $truncatedSummary = $this->truncateContent($summaryText);

        $aioEnabled = (bool) config('trending-summary.seo.aio.enabled', true);
        $aioPromptSection = '';
        $aioSchemaSection = [];

        if ($aioEnabled) {
            $aioPromptSection = <<<'AIO'

            7. AIO 檢查清單（aio_checklist）：
               - question_headings：摘要中 H2/H3 標題是否有 ≥50% 為問句形式（boolean）
               - scannable_formats：摘要中是否包含條列、表格等可掃描格式（boolean）
               - keyword_density：焦點關鍵字在摘要中的密度是否合理（1%-3%）（boolean）
               - source_citation：摘要中是否有引用來源或原文出處（boolean）
            AIO;

            $aioSchemaSection = [
                'aio_checklist' => [
                    'type' => 'object',
                    'properties' => [
                        'question_headings' => [
                            'type' => 'boolean',
                            'description' => '≥50% H2/H3 標題為問句形式',
                        ],
                        'scannable_formats' => [
                            'type' => 'boolean',
                            'description' => '包含條列、表格等可掃描格式',
                        ],
                        'keyword_density' => [
                            'type' => 'boolean',
                            'description' => '焦點關鍵字密度合理（1%-3%）',
                        ],
                        'source_citation' => [
                            'type' => 'boolean',
                            'description' => '有引用來源或原文出處',
                        ],
                    ],
                    'required' => ['question_headings', 'scannable_formats', 'keyword_density', 'source_citation'],
                ],
            ];
        }

        $prompt = <<<PROMPT
        你是繁體中文內容品質檢查專家。請對以下摘要文章執行全面品質檢查。

        【原始文章】
        標題：{$article->original_title}
        內容：{$truncatedContent}

        【生成的摘要】
        標題：{$article->title}
        摘要：{$truncatedSummary}

        請檢查以下項目並回傳結構化結果：

        1. 拼寫與文法問題（spelling_issues）：
           找出摘要中的錯字、語法錯誤、標點符號問題。
           每個問題包含：text（問題文字）、suggestion（建議修正）、position（位置描述）。

        2. 專有名詞翻譯一致性（terminology_issues）：
           找出同一篇文章中對同一專有名詞（人名、組織名、地名等）使用不同翻譯的情況。
           每個問題包含：term（原文詞彙）、variants（出現的不同翻譯版本）、suggested（建議統一使用的翻譯）。

        3. 敏感或爭議性用語（sensitive_terms）：
           找出可能引起爭議、政治敏感、或不當的用語。
           每個項目包含：term（敏感詞彙）、reason（敏感原因）、alternative（建議替代用語）。

        4. 事實參照（fact_references）：
           找出摘要中的關鍵事實聲明（數據、人名、組織名等），並對照原文確認。
           每個項目包含：claim（摘要中的事實聲明）、original_passage（原文對應段落）、verified（是否可在原文中找到對應，boolean）。

        5. SEO 改善建議（seo_suggestions）：
           針對摘要的 SEO 表現提出改善建議。
           每個建議包含：category（類別，如 keyword、structure、readability）、issue（問題描述）、suggestion（改善建議）。

        6. 若無問題，對應欄位回傳空陣列。
        {$aioPromptSection}
        PROMPT;

        $schema = [
            'type' => 'object',
            'properties' => [
                'spelling_issues' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string', 'description' => '問題文字'],
                            'suggestion' => ['type' => 'string', 'description' => '建議修正'],
                            'position' => ['type' => 'string', 'description' => '位置描述'],
                        ],
                        'required' => ['text', 'suggestion', 'position'],
                    ],
                ],
                'terminology_issues' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'term' => ['type' => 'string', 'description' => '原文詞彙'],
                            'variants' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => '出現的不同翻譯版本',
                            ],
                            'suggested' => ['type' => 'string', 'description' => '建議統一使用的翻譯'],
                        ],
                        'required' => ['term', 'variants', 'suggested'],
                    ],
                ],
                'sensitive_terms' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'term' => ['type' => 'string', 'description' => '敏感詞彙'],
                            'reason' => ['type' => 'string', 'description' => '敏感原因'],
                            'alternative' => ['type' => 'string', 'description' => '建議替代用語'],
                        ],
                        'required' => ['term', 'reason', 'alternative'],
                    ],
                ],
                'fact_references' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'claim' => ['type' => 'string', 'description' => '摘要中的事實聲明'],
                            'original_passage' => ['type' => 'string', 'description' => '原文對應段落'],
                            'verified' => ['type' => 'boolean', 'description' => '是否可在原文中找到對應'],
                        ],
                        'required' => ['claim', 'original_passage', 'verified'],
                    ],
                ],
                'seo_suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => '類別'],
                            'issue' => ['type' => 'string', 'description' => '問題描述'],
                            'suggestion' => ['type' => 'string', 'description' => '改善建議'],
                        ],
                        'required' => ['category', 'issue', 'suggestion'],
                    ],
                ],
                ...$aioSchemaSection,
            ],
            'required' => ['spelling_issues', 'terminology_issues', 'sensitive_terms', 'fact_references', 'seo_suggestions'],
        ];

        return $llm->generateStructured($prompt, $schema);
    }

    /**
     * 截斷內容至合理長度。
     *
     * 避免超出 LLM token 限制，將過長的內容截斷並加上截斷提示。
     *
     * @param  string  $content  原始內容
     * @return string  截斷後的內容
     */
    private function truncateContent(string $content): string
    {
        if ($content === '') {
            return '（無內容）';
        }

        if (mb_strlen($content) <= self::MAX_CONTENT_LENGTH) {
            return $content;
        }

        return mb_substr($content, 0, self::MAX_CONTENT_LENGTH) . '…（內容已截斷）';
    }
}
