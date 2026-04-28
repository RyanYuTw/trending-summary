<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Contracts\LlmInterface;
use TnlMedia\TrendingSummary\Models\ArticleTemplate;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * 文章摘要生成服務
 *
 * 負責依選定模板呼叫 LLM（sub-role: generation）產出結構化繁體中文摘要。
 * 透過 AiModelManagerInterface 取得 generation 子角色的 LLM driver，
 * 使用 generateStructured() 依模板的 output_structure 與 settings 生成摘要。
 * 生成完成後更新 status = generated 並記錄 template_id；
 * 支援 regenerate（重新生成）以替換既有摘要；
 * API 失敗 → 保留 filtered 狀態待重試。
 */
class ArticleGeneratorService
{
    /**
     * 文章內容截斷長度（字元數），避免超出 token 限制。
     */
    private const int MAX_CONTENT_LENGTH = 4000;

    /**
     * 預設模板 slug，當未指定模板時使用。
     */
    private const string DEFAULT_TEMPLATE_SLUG = 'trending-summary';

    /**
     * 建構子，注入 AiModelManagerInterface 與 ArticleTemplateService。
     *
     * @param AiModelManagerInterface $aiManager AI 模型管理器
     * @param ArticleTemplateService $templateService 模板管理服務
     */
    public function __construct(
        protected AiModelManagerInterface $aiManager,
        protected ArticleTemplateService $templateService,
    ) {}

    /**
     * 為文章生成結構化摘要。
     *
     * 使用指定模板（或預設模板）透過 LLM 生成結構化繁中摘要，
     * 生成成功後將摘要存入文章的 summary 欄位（JSON 編碼），
     * 並更新 status 為 generated、記錄 template_id。
     * API 失敗時保留 filtered 狀態，記錄錯誤並拋出例外。
     *
     * @param TrendingArticle $article 待生成摘要的文章（status 應為 filtered）
     * @param ArticleTemplate|null $template 指定模板，null 時使用預設模板
     * @return TrendingArticle 更新後的文章
     *
     * @throws \RuntimeException 當找不到預設模板時
     * @throws \Throwable 當 LLM API 呼叫失敗時
     */
    public function generate(TrendingArticle $article, ?ArticleTemplate $template = null): TrendingArticle
    {
        $template = $this->resolveTemplate($template);

        try {
            $prompt = $this->buildGenerationPrompt($article, $template);
            $schema = $this->getGenerationSchema($template);

            /** @var LlmInterface $llm */
            $llm = $this->aiManager->driver('llm', 'generation');

            $result = $llm->generateStructured($prompt, $schema);

            $article->update([
                'summary' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => 'generated',
                'template_id' => $template->id,
            ]);

            Log::info('ArticleGeneratorService: 摘要生成完成', [
                'article_id' => $article->id,
                'template_slug' => $template->slug,
            ]);

            return $article->refresh();
        } catch (\Throwable $e) {
            Log::error('ArticleGeneratorService: 摘要生成失敗，保留 filtered 狀態', [
                'article_id' => $article->id,
                'template_slug' => $template->slug,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 重新生成文章摘要。
     *
     * 與 generate() 相同邏輯，但用於替換既有摘要。
     * 可指定新模板或沿用原模板。API 失敗時保留 filtered 狀態。
     *
     * @param TrendingArticle $article 待重新生成摘要的文章
     * @param ArticleTemplate|null $template 指定模板，null 時使用文章原模板或預設模板
     * @return TrendingArticle 更新後的文章
     *
     * @throws \RuntimeException 當找不到預設模板時
     * @throws \Throwable 當 LLM API 呼叫失敗時
     */
    public function regenerate(TrendingArticle $article, ?ArticleTemplate $template = null): TrendingArticle
    {
        // 若未指定模板，嘗試使用文章原本的模板
        if ($template === null && $article->template_id !== null) {
            $template = $this->templateService->getById($article->template_id);
        }

        // 將狀態回退為 filtered 以便重新生成
        $article->update(['status' => 'filtered']);

        Log::info('ArticleGeneratorService: 開始重新生成摘要', [
            'article_id' => $article->id,
        ]);

        return $this->generate($article, $template);
    }

    /**
     * 建構 LLM 摘要生成的提示詞。
     *
     * 包含文章標題、截斷後的內容、模板指示（語調、結構、字數限制），
     * 要求 LLM 依模板的 output_structure 產出結構化繁體中文摘要。
     *
     * @param TrendingArticle $article 待生成摘要的文章
     * @param ArticleTemplate $template 使用的模板
     * @return string 完整的提示詞
     */
    public function buildGenerationPrompt(TrendingArticle $article, ArticleTemplate $template): string
    {
        $title = trim((string) $article->title);
        $content = $this->truncateContent((string) $article->content_body);
        $settings = $template->settings ?? [];
        $outputStructure = $template->output_structure ?? [];

        $tone = $settings['tone'] ?? 'informative';
        $useEmoji = ($settings['use_emoji'] ?? false) ? '是' : '否';
        $maxParagraphs = $settings['max_paragraphs'] ?? 5;
        $maxTotalChars = $settings['max_total_chars'] ?? 2000;

        // 建構輸出結構說明
        $structureDescription = $this->buildStructureDescription($outputStructure);

        // 建構趨勢關鍵字說明
        $trendKeywords = $article->trend_keywords;
        $keywordSection = '';
        if (is_array($trendKeywords) && $trendKeywords !== []) {
            $keywordList = implode('、', $trendKeywords);
            $keywordSection = <<<SECTION

## 相關趨勢關鍵字
{$keywordList}
請在摘要中自然融入上述趨勢關鍵字。
SECTION;
        }

        return <<<PROMPT
你是一位專業的繁體中文內容編輯。請根據以下原始文章內容，依照指定的模板格式產出結構化的繁體中文摘要。

## 原始文章標題
{$title}

## 原始文章內容
{$content}
{$keywordSection}

## 模板名稱
{$template->name}

## 模板說明
{$template->description}

## 寫作要求
- 語調風格：{$tone}
- 使用 Emoji：{$useEmoji}
- 最大段落數：{$maxParagraphs}
- 最大總字數：{$maxTotalChars} 字元
- 語言：繁體中文
- 請確保內容準確反映原文要點，不要捏造資訊

## 輸出結構
請嚴格按照以下結構輸出 JSON：
{$structureDescription}

## 回應要求
請以 JSON 格式回應，包含上述所有必填欄位。
PROMPT;
    }

    /**
     * 依模板的 output_structure 建構 JSON Schema。
     *
     * 將模板定義的欄位結構轉換為 LLM generateStructured() 所需的 JSON Schema，
     * 用於約束 LLM 的結構化輸出格式。
     *
     * @param ArticleTemplate $template 使用的模板
     * @return array<string, mixed> JSON Schema 定義
     */
    public function getGenerationSchema(ArticleTemplate $template): array
    {
        $outputStructure = $template->output_structure ?? [];
        $properties = [];
        $required = [];

        foreach ($outputStructure as $field) {
            $fieldName = $field['field'] ?? '';
            $fieldType = $field['type'] ?? 'string';
            $fieldLabel = $field['label'] ?? $fieldName;
            $isRequired = (bool) ($field['required'] ?? false);

            if ($fieldName === '') {
                continue;
            }

            $properties[$fieldName] = $this->mapFieldTypeToSchema($fieldType, $fieldLabel);

            if ($isRequired) {
                $required[] = $fieldName;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * 將模板欄位類型映射為 JSON Schema 屬性定義。
     *
     * 支援的類型：string、text、list、numbered_list、sections、table。
     * 未知類型預設為 string。
     *
     * @param string $fieldType 模板欄位類型
     * @param string $label 欄位標籤（用於 description）
     * @return array<string, mixed> JSON Schema 屬性定義
     */
    private function mapFieldTypeToSchema(string $fieldType, string $label): array
    {
        return match ($fieldType) {
            'string' => [
                'type' => 'string',
                'description' => $label,
            ],
            'text' => [
                'type' => 'string',
                'description' => $label,
            ],
            'list', 'numbered_list' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => $label,
            ],
            'sections' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'heading' => ['type' => 'string', 'description' => '段落標題'],
                        'content' => ['type' => 'string', 'description' => '段落內容'],
                    ],
                    'required' => ['heading', 'content'],
                ],
                'description' => $label,
            ],
            'table' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'description' => $label,
            ],
            default => [
                'type' => 'string',
                'description' => $label,
            ],
        };
    }

    /**
     * 建構輸出結構的文字說明。
     *
     * 將模板的 output_structure 轉換為人類可讀的結構說明，
     * 供 LLM 理解期望的輸出格式。
     *
     * @param array<int, array<string, mixed>> $outputStructure 模板輸出結構定義
     * @return string 結構說明文字
     */
    private function buildStructureDescription(array $outputStructure): string
    {
        $lines = [];

        foreach ($outputStructure as $field) {
            $fieldName = $field['field'] ?? '';
            $fieldType = $field['type'] ?? 'string';
            $fieldLabel = $field['label'] ?? $fieldName;
            $isRequired = ($field['required'] ?? false) ? '必填' : '選填';

            $typeDescription = match ($fieldType) {
                'string' => '字串',
                'text' => '長文字',
                'list' => '字串陣列（條列項目）',
                'numbered_list' => '字串陣列（編號清單）',
                'sections' => '物件陣列，每個物件含 heading（標題）與 content（內容）',
                'table' => '物件陣列，每個物件為一列資料（鍵值對）',
                default => '字串',
            };

            $lines[] = "- `{$fieldName}`（{$fieldLabel}）：{$typeDescription}，{$isRequired}";
        }

        return implode("\n", $lines);
    }

    /**
     * 解析要使用的模板。
     *
     * 若已指定模板則直接使用，否則嘗試取得預設模板（trending-summary）。
     * 找不到預設模板時拋出例外。
     *
     * @param ArticleTemplate|null $template 指定的模板
     * @return ArticleTemplate 解析後的模板
     *
     * @throws \RuntimeException 當找不到預設模板時
     */
    private function resolveTemplate(?ArticleTemplate $template): ArticleTemplate
    {
        if ($template !== null) {
            return $template;
        }

        $defaultTemplate = $this->templateService->getBySlug(self::DEFAULT_TEMPLATE_SLUG);

        if ($defaultTemplate === null) {
            $slug = self::DEFAULT_TEMPLATE_SLUG;

            throw new \RuntimeException(
                "找不到預設模板 [{$slug}]，請先執行 DefaultTemplateSeeder。"
            );
        }

        return $defaultTemplate;
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
