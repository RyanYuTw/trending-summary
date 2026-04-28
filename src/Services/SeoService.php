<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Contracts\LlmInterface;
use TnlMedia\TrendingSummary\Models\ArticleSeo;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * SEO 自動補全與 GEO 優化服務
 *
 * 負責在摘要完成後自動產生完整的 SEO 資料，包含 Meta 標籤、Open Graph、
 * Twitter Card、JSON-LD 結構化資料、關鍵字提取等。同時實作 Google AI Overview
 * （GEO）優化功能：Direct Answer Block、問句式標題改寫、FAQ 產生、
 * 可掃描格式套用、最低內容長度檢查。
 *
 * 透過 AiModelManagerInterface 取得 seo 子角色的 LLM driver，
 * 使用 generateStructured() 進行關鍵字提取、FAQ 生成、Direct Answer Block 產生等。
 * 所有 SEO 資料儲存於 ArticleSeo 模型，支援手動覆寫。
 */
class SeoService
{
    /**
     * 文章內容截斷長度（字元數），避免超出 token 限制。
     */
    private const int MAX_CONTENT_LENGTH = 3000;

    /**
     * 建構子，注入 AiModelManagerInterface。
     *
     * @param AiModelManagerInterface $aiManager AI 模型管理器
     */
    public function __construct(
        protected AiModelManagerInterface $aiManager,
    ) {}

    /**
     * 為文章生成完整 SEO 資料（主方法）。
     *
     * 依序產生 meta_title、meta_description、slug、關鍵字、canonical_url、
     * OG tags、Twitter Card、JSON-LD，以及 GEO 優化內容（Direct Answer Block、
     * FAQ、問句式標題改寫、可掃描格式）。所有資料透過 createOrUpdate 儲存至 ArticleSeo。
     *
     * @param TrendingArticle $article 待產生 SEO 資料的文章（應已有摘要）
     * @return ArticleSeo 產生的 SEO 資料
     *
     * @throws \Throwable 當 LLM API 呼叫失敗時
     */
    public function generateSeo(TrendingArticle $article): ArticleSeo
    {
        try {
            $metaTitle = $this->generateMetaTitle($article);
            $metaDescription = $this->generateMetaDescription($article);
            $slug = $this->generateSlug($article);
            [$focusKeyword, $secondaryKeywords] = $this->generateKeywords($article);
            $canonicalUrl = $this->resolveCanonicalUrl($article);

            $seoData = [
                'article_id' => $article->id,
                'meta_title' => $metaTitle,
                'meta_description' => $metaDescription,
                'slug' => $slug,
                'focus_keyword' => $focusKeyword,
                'secondary_keywords' => $secondaryKeywords,
                'canonical_url' => $canonicalUrl,
            ];

            // 先建立或更新基礎 SEO 資料，以便後續方法可引用
            $seo = ArticleSeo::updateOrCreate(
                ['article_id' => $article->id],
                $seoData,
            );

            // 產生 OG、Twitter、JSON-LD（依賴 seo 物件）
            $ogData = $this->generateOgData($article, $seo);
            $twitterData = $this->generateTwitterData($article);
            $jsonLd = $this->generateJsonLd($article, $seo);

            // GEO 優化
            $geoData = $this->generateGeoData($article);

            $seo->update(array_merge(
                [
                    'og_data' => $ogData,
                    'twitter_data' => $twitterData,
                    'json_ld' => $jsonLd,
                ],
                $geoData,
            ));

            Log::info('SeoService: SEO 資料生成完成', [
                'article_id' => $article->id,
                'slug' => $slug,
                'focus_keyword' => $focusKeyword,
            ]);

            return $seo->refresh();
        } catch (\Throwable $e) {
            Log::error('SeoService: SEO 資料生成失敗', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 產生 Meta Title（≤60 字元）。
     *
     * 透過 LLM 將文章標題改寫為適合搜尋引擎的 meta title，
     * 確保長度不超過 60 字元。若 LLM 結果超長則截斷。
     *
     * @param TrendingArticle $article 文章
     * @return string Meta Title（≤60 字元）
     */
    public function generateMetaTitle(TrendingArticle $article): string
    {
        $maxLength = (int) config('trending-summary.seo.meta_title_max', 60);
        $title = trim((string) ($article->selected_title ?: $article->title));

        // 若標題已在限制內，直接使用
        if (mb_strlen($title) <= $maxLength) {
            return $title;
        }

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'seo');

        $result = $llm->generateStructured(
            <<<PROMPT
            你是 SEO 專家。請將以下標題改寫為適合搜尋引擎的 meta title。
            要求：繁體中文、不超過 {$maxLength} 個字元、保留核心語意與關鍵字。

            原始標題：{$title}
            PROMPT,
            [
                'type' => 'object',
                'properties' => [
                    'meta_title' => [
                        'type' => 'string',
                        'description' => "Meta title，不超過 {$maxLength} 字元",
                    ],
                ],
                'required' => ['meta_title'],
            ],
        );

        $metaTitle = trim((string) ($result['meta_title'] ?? $title));

        // 安全截斷
        if (mb_strlen($metaTitle) > $maxLength) {
            $metaTitle = mb_substr($metaTitle, 0, $maxLength - 1) . '…';
        }

        return $metaTitle;
    }

    /**
     * 產生 Meta Description（≤160 字元）。
     *
     * 透過 LLM 根據文章摘要產生精簡的 meta description，
     * 確保長度不超過 160 字元。
     *
     * @param TrendingArticle $article 文章
     * @return string Meta Description（≤160 字元）
     */
    public function generateMetaDescription(TrendingArticle $article): string
    {
        $maxLength = (int) config('trending-summary.seo.meta_description_max', 160);
        $content = $this->resolveArticleContent($article);

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'seo');

        $result = $llm->generateStructured(
            <<<PROMPT
            你是 SEO 專家。請根據以下文章內容產生一段精簡的 meta description。
            要求：繁體中文、不超過 {$maxLength} 個字元、吸引點擊、包含核心關鍵字。

            文章標題：{$article->title}
            文章內容：{$content}
            PROMPT,
            [
                'type' => 'object',
                'properties' => [
                    'meta_description' => [
                        'type' => 'string',
                        'description' => "Meta description，不超過 {$maxLength} 字元",
                    ],
                ],
                'required' => ['meta_description'],
            ],
        );

        $metaDescription = trim((string) ($result['meta_description'] ?? ''));

        if ($metaDescription === '') {
            $metaDescription = mb_substr(strip_tags($content), 0, $maxLength);
        }

        // 安全截斷
        if (mb_strlen($metaDescription) > $maxLength) {
            $metaDescription = mb_substr($metaDescription, 0, $maxLength - 1) . '…';
        }

        return $metaDescription;
    }

    /**
     * 產生 URL Slug（英文 kebab-case）。
     *
     * 透過 LLM 將文章標題翻譯為英文，再轉換為 kebab-case slug。
     * 限制最大字數（預設 8 個單字）。
     *
     * @param TrendingArticle $article 文章
     * @return string 英文 kebab-case slug
     */
    public function generateSlug(TrendingArticle $article): string
    {
        $maxWords = (int) config('trending-summary.seo.slug_max_words', 8);
        $title = trim((string) ($article->selected_title ?: $article->title));

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'seo');

        $result = $llm->generateStructured(
            <<<PROMPT
            請將以下標題翻譯為簡短的英文短語，用於 URL slug。
            要求：只輸出英文單字、不超過 {$maxWords} 個單字、以空格分隔、全小寫。

            標題：{$title}
            PROMPT,
            [
                'type' => 'object',
                'properties' => [
                    'english_phrase' => [
                        'type' => 'string',
                        'description' => '英文短語，用於 URL slug',
                    ],
                ],
                'required' => ['english_phrase'],
            ],
        );

        $englishPhrase = trim((string) ($result['english_phrase'] ?? ''));

        if ($englishPhrase === '') {
            $englishPhrase = 'untitled-article';
        }

        // 轉換為 kebab-case
        $slug = Str::slug($englishPhrase);

        // 限制字數
        $parts = explode('-', $slug);
        if (count($parts) > $maxWords) {
            $slug = implode('-', array_slice($parts, 0, $maxWords));
        }

        // 確保 slug 不為空
        if ($slug === '' || $slug === '0') {
            $slug = 'article-' . $article->id;
        }

        return $slug;
    }

    /**
     * 提取文章關鍵字（focus_keyword + secondary_keywords）。
     *
     * 透過 LLM 從文章內容中提取一個主要關鍵字與 3-5 個次要關鍵字。
     *
     * @param TrendingArticle $article 文章
     * @return array{0: string, 1: array<int, string>} [focus_keyword, secondary_keywords]
     */
    public function generateKeywords(TrendingArticle $article): array
    {
        $content = $this->resolveArticleContent($article);
        $trendKeywords = is_array($article->trend_keywords) ? $article->trend_keywords : [];

        $keywordHint = '';
        if ($trendKeywords !== []) {
            $keywordList = implode('、', $trendKeywords);
            $keywordHint = "\n相關趨勢關鍵字供參考：{$keywordList}";
        }

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'seo');

        $result = $llm->generateStructured(
            <<<PROMPT
            你是 SEO 關鍵字分析專家。請從以下文章中提取關鍵字。
            要求：
            - 1 個主要關鍵字（focus_keyword）：最能代表文章主題的詞彙
            - 3 到 5 個次要關鍵字（secondary_keywords）：與主題相關的延伸詞彙
            - 所有關鍵字使用繁體中文
            {$keywordHint}

            文章標題：{$article->title}
            文章內容：{$content}
            PROMPT,
            [
                'type' => 'object',
                'properties' => [
                    'focus_keyword' => [
                        'type' => 'string',
                        'description' => '主要關鍵字',
                    ],
                    'secondary_keywords' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'minItems' => 3,
                        'maxItems' => 5,
                        'description' => '3 到 5 個次要關鍵字',
                    ],
                ],
                'required' => ['focus_keyword', 'secondary_keywords'],
            ],
        );

        $focusKeyword = trim((string) ($result['focus_keyword'] ?? ''));
        /** @var array<int, string> $secondaryKeywords */
        $secondaryKeywords = array_values(array_filter(
            array_map('trim', (array) ($result['secondary_keywords'] ?? [])),
            fn (string $kw): bool => $kw !== '',
        ));

        // 確保次要關鍵字數量在 3-5 之間
        if (count($secondaryKeywords) < 3) {
            // 補足至 3 個（使用趨勢關鍵字填充）
            foreach ($trendKeywords as $kw) {
                if (count($secondaryKeywords) >= 3) {
                    break;
                }
                $kw = trim((string) $kw);
                if ($kw !== '' && $kw !== $focusKeyword && ! in_array($kw, $secondaryKeywords, true)) {
                    $secondaryKeywords[] = $kw;
                }
            }
        }

        if (count($secondaryKeywords) > 5) {
            $secondaryKeywords = array_slice($secondaryKeywords, 0, 5);
        }

        return [$focusKeyword, $secondaryKeywords];
    }

    /**
     * 產生 Open Graph 標籤資料。
     *
     * 根據文章標題、摘要、配圖與 SEO 資料產生完整的 OG tags，
     * 包含 og:title、og:description、og:image、og:type、og:url、og:site_name、og:locale。
     *
     * @param TrendingArticle $article 文章
     * @param ArticleSeo $seo SEO 資料（用於取得 slug、canonical_url）
     * @return array<string, string|null> OG 標籤資料
     */
    public function generateOgData(TrendingArticle $article, ArticleSeo $seo): array
    {
        $siteUrl = rtrim((string) config('trending-summary.seo.site_url', ''), '/');
        $siteName = (string) config('trending-summary.seo.site_name', 'Trending Summary');
        $locale = (string) config('trending-summary.seo.locale', 'zh_TW');

        // 取得文章配圖 URL
        $imageUrl = $article->image?->url;

        // 決定 og:url
        $ogUrl = $seo->canonical_url ?: ($siteUrl . '/' . $seo->slug);

        // 決定 og:type
        $ogType = $article->content_type === 'video' ? 'video.other' : 'article';

        return [
            'og:title' => $seo->meta_title,
            'og:description' => $seo->meta_description,
            'og:image' => $imageUrl,
            'og:type' => $ogType,
            'og:url' => $ogUrl,
            'og:site_name' => $siteName,
            'og:locale' => $locale,
        ];
    }

    /**
     * 產生 Twitter Card 資料。
     *
     * 依 content_type 設定 Twitter Card 類型：
     * - article → summary_large_image
     * - video → player
     *
     * @param TrendingArticle $article 文章
     * @return array<string, string> Twitter Card 資料
     */
    public function generateTwitterData(TrendingArticle $article): array
    {
        $cardType = $article->content_type === 'video' ? 'player' : 'summary_large_image';

        $title = trim((string) ($article->selected_title ?: $article->title));
        $imageUrl = $article->image?->url ?? '';

        return [
            'twitter:card' => $cardType,
            'twitter:title' => mb_strlen($title) > 70 ? mb_substr($title, 0, 67) . '…' : $title,
            'twitter:image' => $imageUrl,
        ];
    }

    /**
     * 產生 JSON-LD 結構化資料。
     *
     * 依 content_type 設定 @type：
     * - article → Article（含 headline、description、image、datePublished 等）
     * - video → VideoObject（額外含 duration、thumbnailUrl、contentUrl、uploadDate）
     *
     * @param TrendingArticle $article 文章
     * @param ArticleSeo $seo SEO 資料
     * @return array<string, mixed> JSON-LD 結構化資料
     */
    public function generateJsonLd(TrendingArticle $article, ArticleSeo $seo): array
    {
        $siteUrl = rtrim((string) config('trending-summary.seo.site_url', ''), '/');
        $siteName = (string) config('trending-summary.seo.site_name', 'Trending Summary');
        $siteLogo = (string) config('trending-summary.seo.site_logo', '');
        $authorType = (string) config('trending-summary.seo.author_type', 'Organization');
        $authorName = (string) config('trending-summary.seo.author_name', 'Trending Summary');

        $imageUrl = $article->image?->url ?? '';
        $articleUrl = $seo->canonical_url ?: ($siteUrl . '/' . $seo->slug);
        $now = now()->toIso8601String();
        $publishedAt = $article->published_at?->toIso8601String() ?? $article->created_at?->toIso8601String() ?? $now;

        $jsonLd = [
            '@context' => 'https://schema.org',
            'headline' => $seo->meta_title,
            'description' => $seo->meta_description,
            'image' => $imageUrl,
            'datePublished' => $publishedAt,
            'dateModified' => $article->updated_at?->toIso8601String() ?? $now,
            'author' => [
                '@type' => $authorType,
                'name' => $authorName,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => $siteName,
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $siteLogo,
                ],
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $articleUrl,
            ],
        ];

        if ($article->content_type === 'video') {
            $jsonLd['@type'] = 'VideoObject';
            $jsonLd['name'] = $seo->meta_title;
            $jsonLd['thumbnailUrl'] = $imageUrl;
            $jsonLd['contentUrl'] = $article->original_url ?? '';
            $jsonLd['uploadDate'] = $publishedAt;
            $jsonLd['duration'] = ''; // 由外部補充或手動覆寫
        } else {
            $jsonLd['@type'] = 'Article';
        }

        return $jsonLd;
    }

    /**
     * 產生 Direct Answer Block（3-5 句直接回答段落）。
     *
     * 透過 LLM 根據文章內容產生 3-5 句精簡的直接回答段落，
     * 供 Google AI Overview 提取，置於摘要開頭。
     * 僅在 config 啟用 AIO 與 direct_answer_block 時產生。
     *
     * @param TrendingArticle $article 文章
     * @return string Direct Answer Block 文字，未啟用時回傳空字串
     */
    public function generateDirectAnswerBlock(TrendingArticle $article): string
    {
        if (! $this->isAioFeatureEnabled('direct_answer_block')) {
            return '';
        }

        $sentences = (int) config('trending-summary.seo.aio.direct_answer_sentences', 4);
        $content = $this->resolveArticleContent($article);

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'seo');

        $result = $llm->generateStructured(
            <<<PROMPT
            你是內容優化專家。請根據以下文章內容，產生一段 Direct Answer Block。
            要求：
            - {$sentences} 句話（3 到 5 句）
            - 直接回答文章的核心問題或主題
            - 精簡、資訊密度高、適合被 AI 搜尋引擎引用
            - 繁體中文
            - 不要使用標題或標記，只輸出純文字段落

            文章標題：{$article->title}
            文章內容：{$content}
            PROMPT,
            [
                'type' => 'object',
                'properties' => [
                    'direct_answer_block' => [
                        'type' => 'string',
                        'description' => 'Direct Answer Block 段落',
                    ],
                ],
                'required' => ['direct_answer_block'],
            ],
        );

        return trim((string) ($result['direct_answer_block'] ?? ''));
    }

    /**
     * 改寫標題為問句形式（≥50% H2/H3）。
     *
     * 透過 LLM 將文章摘要中的 H2/H3 標題改寫為問句形式，
     * 確保至少 50% 的標題為問句。僅在 config 啟用時執行。
     *
     * @param TrendingArticle $article 文章
     * @return array<int, array{original: string, question: string}> 改寫結果
     */
    public function rewriteHeadingsAsQuestions(TrendingArticle $article): array
    {
        if (! $this->isAioFeatureEnabled('question_headings')) {
            return [];
        }

        $content = trim((string) $article->summary);
        if ($content === '') {
            return [];
        }

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'seo');

        $result = $llm->generateStructured(
            <<<PROMPT
            你是內容優化專家。請從以下文章摘要中找出所有 H2 和 H3 標題，
            並將至少 50% 的標題改寫為問句形式。

            要求：
            - 保留原始語意
            - 問句應自然、吸引讀者
            - 繁體中文
            - 回傳所有標題的原始版本與改寫版本

            文章摘要：
            {$content}
            PROMPT,
            [
                'type' => 'object',
                'properties' => [
                    'headings' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'original' => ['type' => 'string', 'description' => '原始標題'],
                                'question' => ['type' => 'string', 'description' => '改寫為問句的標題'],
                            ],
                            'required' => ['original', 'question'],
                        ],
                        'description' => '標題改寫結果',
                    ],
                ],
                'required' => ['headings'],
            ],
        );

        /** @var array<int, array{original: string, question: string}> */
        return $result['headings'] ?? [];
    }

    /**
     * 產生 FAQ 問答對（3-5 組）。
     *
     * 透過 LLM 根據文章內容產生 3-5 組 FAQ 問答對，
     * 附加於摘要末尾。僅在 config 啟用 FAQ 功能時產生。
     *
     * @param TrendingArticle $article 文章
     * @return array<int, array{question: string, answer: string}> FAQ 問答對
     */
    public function generateFaq(TrendingArticle $article): array
    {
        if (! $this->isAioFeatureEnabled('faq_enabled')) {
            return [];
        }

        $faqCount = (int) config('trending-summary.seo.aio.faq_count', 4);
        $content = $this->resolveArticleContent($article);

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'seo');

        $result = $llm->generateStructured(
            <<<PROMPT
            你是內容優化專家。請根據以下文章內容，產生 {$faqCount} 組常見問答（FAQ）。
            要求：
            - 每組包含一個問題（question）與一個回答（answer）
            - 問題應是讀者可能會搜尋的問題
            - 回答應精簡、準確、2-4 句話
            - 繁體中文
            - 產生 3 到 5 組

            文章標題：{$article->title}
            文章內容：{$content}
            PROMPT,
            [
                'type' => 'object',
                'properties' => [
                    'faq_items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'question' => ['type' => 'string', 'description' => '問題'],
                                'answer' => ['type' => 'string', 'description' => '回答'],
                            ],
                            'required' => ['question', 'answer'],
                        ],
                        'minItems' => 3,
                        'maxItems' => 5,
                        'description' => 'FAQ 問答對',
                    ],
                ],
                'required' => ['faq_items'],
            ],
        );

        /** @var array<int, array{question: string, answer: string}> $faqItems */
        $faqItems = $result['faq_items'] ?? [];

        // 過濾無效項目
        return array_values(array_filter(
            $faqItems,
            fn (array $item): bool => trim((string) ($item['question'] ?? '')) !== ''
                && trim((string) ($item['answer'] ?? '')) !== '',
        ));
    }

    /**
     * 產生 FAQPage JSON-LD Schema。
     *
     * 根據 FAQ 問答對產生符合 schema.org 規範的 FAQPage 結構化資料，
     * 包含 @type FAQPage 與 mainEntity 陣列（每個 Question 含 acceptedAnswer）。
     *
     * @param array<int, array{question: string, answer: string}> $faqItems FAQ 問答對
     * @return array<string, mixed> FAQPage JSON-LD，無 FAQ 時回傳空陣列
     */
    public function generateFaqSchema(array $faqItems): array
    {
        if ($faqItems === []) {
            return [];
        }

        if (! config('trending-summary.seo.aio.faq_schema_enabled', true)) {
            return [];
        }

        $mainEntity = [];

        foreach ($faqItems as $item) {
            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $mainEntity,
        ];
    }

    /**
     * 套用可掃描格式（條列、表格、定義配對）。
     *
     * 透過 LLM 分析文章摘要，建議適合的可掃描格式（numbered list、bullet points、
     * comparison table、definition pairs），並產生格式化建議。
     * 僅在 config 啟用 scannable_formats 時執行。
     *
     * @param TrendingArticle $article 文章
     * @return array<int, array{type: string, content: string}> 可掃描格式建議
     */
    public function generateScannableFormats(TrendingArticle $article): array
    {
        if (! $this->isAioFeatureEnabled('scannable_formats')) {
            return [];
        }

        $content = $this->resolveArticleContent($article);

        /** @var LlmInterface $llm */
        $llm = $this->aiManager->driver('llm', 'seo');

        $result = $llm->generateStructured(
            <<<PROMPT
            你是內容格式優化專家。請分析以下文章內容，產生適合的可掃描格式內容。
            可用格式類型：numbered_list（編號清單）、bullet_points（條列）、
            comparison_table（比較表格）、definition_pairs（定義配對）。

            要求：
            - 至少產生 1 種格式
            - 內容應從文章中提取關鍵資訊
            - 繁體中文

            文章內容：{$content}
            PROMPT,
            [
                'type' => 'object',
                'properties' => [
                    'formats' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => [
                                    'type' => 'string',
                                    'enum' => ['numbered_list', 'bullet_points', 'comparison_table', 'definition_pairs'],
                                    'description' => '格式類型',
                                ],
                                'content' => ['type' => 'string', 'description' => '格式化內容（Markdown）'],
                            ],
                            'required' => ['type', 'content'],
                        ],
                        'description' => '可掃描格式建議',
                    ],
                ],
                'required' => ['formats'],
            ],
        );

        return $result['formats'] ?? [];
    }

    /**
     * 檢查內容是否達到最低長度要求。
     *
     * 驗證文章摘要是否達到 AIO 所需的最低內容長度（預設 300 字元），
     * 過短的內容不易被 AI Overview 引用。
     *
     * @param TrendingArticle $article 文章
     * @return bool 是否達到最低長度
     */
    public function checkMinContentLength(TrendingArticle $article): bool
    {
        $minLength = (int) config('trending-summary.seo.aio.min_content_length', 300);
        $summary = trim((string) $article->summary);

        return mb_strlen($summary) >= $minLength;
    }

    /**
     * 手動覆寫 SEO 欄位。
     *
     * 接受使用者手動編輯的 SEO 欄位，覆寫自動產生的值。
     * 僅更新提供的欄位，未提供的欄位保持不變。
     *
     * @param ArticleSeo $seo 現有的 SEO 資料
     * @param array<string, mixed> $overrides 要覆寫的欄位（鍵值對）
     * @return ArticleSeo 更新後的 SEO 資料
     */
    public function updateSeo(ArticleSeo $seo, array $overrides): ArticleSeo
    {
        // 允許覆寫的欄位白名單
        $allowedFields = [
            'meta_title',
            'meta_description',
            'slug',
            'canonical_url',
            'og_data',
            'twitter_data',
            'json_ld',
            'focus_keyword',
            'secondary_keywords',
            'direct_answer_block',
            'faq_items',
            'faq_schema',
            'aio_checklist',
        ];

        $filteredOverrides = array_intersect_key($overrides, array_flip($allowedFields));

        if ($filteredOverrides !== []) {
            $seo->update($filteredOverrides);

            Log::info('SeoService: SEO 欄位手動覆寫', [
                'article_id' => $seo->article_id,
                'overridden_fields' => array_keys($filteredOverrides),
            ]);
        }

        return $seo->refresh();
    }

    // ──────────────────────────────────────────────────────────
    // 私有輔助方法
    // ──────────────────────────────────────────────────────────

    /**
     * 解析 Canonical URL。
     *
     * 依 config 設定決定 canonical URL 指向原文或自家 URL。
     * canonical_to_original = true → 指向原文 URL
     * canonical_to_original = false → 回傳 null（由前端或發佈時決定）
     *
     * @param TrendingArticle $article 文章
     * @return string|null Canonical URL
     */
    private function resolveCanonicalUrl(TrendingArticle $article): ?string
    {
        $canonicalToOriginal = (bool) config('trending-summary.seo.canonical_to_original', true);

        if ($canonicalToOriginal && $article->original_url) {
            return $article->original_url;
        }

        return null;
    }

    /**
     * 產生 GEO 優化資料（整合 Direct Answer Block、FAQ、可掃描格式）。
     *
     * 統一呼叫各 GEO 優化方法，回傳可直接 merge 至 ArticleSeo 的資料陣列。
     *
     * @param TrendingArticle $article 文章
     * @return array<string, mixed> GEO 優化資料
     */
    private function generateGeoData(TrendingArticle $article): array
    {
        $data = [];

        // Direct Answer Block
        $directAnswerBlock = $this->generateDirectAnswerBlock($article);
        if ($directAnswerBlock !== '') {
            $data['direct_answer_block'] = $directAnswerBlock;
        }

        // FAQ
        $faqItems = $this->generateFaq($article);
        if ($faqItems !== []) {
            $data['faq_items'] = $faqItems;
            $data['faq_schema'] = $this->generateFaqSchema($faqItems);
        }

        // AIO 檢查清單
        $data['aio_checklist'] = $this->buildAioChecklist($article, $directAnswerBlock, $faqItems);

        return $data;
    }

    /**
     * 建構 AIO 檢查清單。
     *
     * 檢查各項 GEO 優化項目是否已完成，產生結構化的檢查清單。
     *
     * @param TrendingArticle $article 文章
     * @param string $directAnswerBlock Direct Answer Block 內容
     * @param array<int, array{question: string, answer: string}> $faqItems FAQ 問答對
     * @return array<string, bool> AIO 檢查清單
     */
    private function buildAioChecklist(TrendingArticle $article, string $directAnswerBlock, array $faqItems): array
    {
        $aioEnabled = (bool) config('trending-summary.seo.aio.enabled', true);

        if (! $aioEnabled) {
            return [];
        }

        return [
            'direct_answer_block' => $directAnswerBlock !== '',
            'question_headings' => (bool) config('trending-summary.seo.aio.question_headings', true),
            'scannable_formats' => (bool) config('trending-summary.seo.aio.scannable_formats', true),
            'faq_present' => $faqItems !== [],
            'faq_schema_valid' => $faqItems !== [] && (bool) config('trending-summary.seo.aio.faq_schema_enabled', true),
            'min_content_length' => $this->checkMinContentLength($article),
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
     * 檢查指定的 AIO 功能是否啟用。
     *
     * 先檢查 AIO 總開關，再檢查個別功能開關。
     *
     * @param string $feature 功能名稱（對應 config key）
     * @return bool 是否啟用
     */
    private function isAioFeatureEnabled(string $feature): bool
    {
        $aioEnabled = (bool) config('trending-summary.seo.aio.enabled', true);

        if (! $aioEnabled) {
            return false;
        }

        return (bool) config("trending-summary.seo.aio.{$feature}", true);
    }
}
