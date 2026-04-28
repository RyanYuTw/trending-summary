<?php

declare(strict_types=1);

use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Contracts\LlmInterface;
use TnlMedia\TrendingSummary\Models\ArticleTemplate;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\ArticleGeneratorService;
use TnlMedia\TrendingSummary\Services\ArticleTemplateService;

/**
 * 建立有效的模板資料。
 */
function createTestTemplate(array $overrides = []): ArticleTemplate
{
    return ArticleTemplate::create(array_merge([
        'slug' => 'trending-summary',
        'name' => '趨勢摘要',
        'description' => '快速摘要當前趨勢文章的重點。',
        'is_builtin' => true,
        'output_structure' => [
            ['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true],
            ['field' => 'introduction', 'type' => 'text', 'label' => '導言', 'required' => true],
            ['field' => 'key_points', 'type' => 'list', 'label' => '重點摘要', 'required' => true],
            ['field' => 'summary_paragraphs', 'type' => 'text', 'label' => '摘要段落', 'required' => true],
            ['field' => 'conclusion', 'type' => 'text', 'label' => '結語', 'required' => true],
        ],
        'settings' => [
            'tone' => 'informative',
            'use_emoji' => false,
            'max_paragraphs' => 5,
            'max_total_chars' => 2000,
        ],
    ], $overrides));
}

/**
 * 建立測試用文章。
 */
function createTestArticle(array $overrides = []): TrendingArticle
{
    return TrendingArticle::create(array_merge([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'title' => '測試文章標題',
        'original_title' => 'Test Article Title',
        'original_url' => 'https://example.com/article-' . uniqid(),
        'source_name' => 'Test Source',
        'content_type' => 'article',
        'content_body' => '這是一篇測試文章的內容，包含了許多有趣的資訊。',
        'status' => 'filtered',
        'relevance_score' => 0.85,
        'trend_keywords' => ['AI', '科技趨勢'],
    ], $overrides));
}

/**
 * 建立 mock LLM 回應。
 */
function mockLlmResponse(): array
{
    return [
        'title' => '測試摘要標題',
        'introduction' => '這是一篇關於最新科技趨勢的摘要。',
        'key_points' => ['重點一', '重點二', '重點三'],
        'summary_paragraphs' => '摘要段落內容。',
        'conclusion' => '結語內容。',
    ];
}

beforeEach(function () {
    $this->mockLlm = Mockery::mock(LlmInterface::class);
    $this->mockAiManager = Mockery::mock(AiModelManagerInterface::class);
    $this->templateService = new ArticleTemplateService();

    $this->service = new ArticleGeneratorService(
        $this->mockAiManager,
        $this->templateService,
    );
});

afterEach(function () {
    Mockery::close();
});

// --- generate ---

it('使用指定模板生成摘要', function () {
    $template = createTestTemplate();
    $article = createTestArticle();

    $this->mockAiManager
        ->shouldReceive('driver')
        ->with('llm', 'generation')
        ->once()
        ->andReturn($this->mockLlm);

    $this->mockLlm
        ->shouldReceive('generateStructured')
        ->once()
        ->andReturn(mockLlmResponse());

    $result = $this->service->generate($article, $template);

    expect($result->status)->toBe('generated');
    expect($result->template_id)->toBe($template->id);
    expect($result->summary)->not->toBeNull();

    $summary = json_decode($result->summary, true);
    expect($summary)->toHaveKey('title');
    expect($summary)->toHaveKey('introduction');
    expect($summary)->toHaveKey('key_points');
    expect($summary['title'])->toBe('測試摘要標題');
});

it('未指定模板時使用預設模板', function () {
    $template = createTestTemplate();
    $article = createTestArticle();

    $this->mockAiManager
        ->shouldReceive('driver')
        ->with('llm', 'generation')
        ->once()
        ->andReturn($this->mockLlm);

    $this->mockLlm
        ->shouldReceive('generateStructured')
        ->once()
        ->andReturn(mockLlmResponse());

    $result = $this->service->generate($article);

    expect($result->status)->toBe('generated');
    expect($result->template_id)->toBe($template->id);
});

it('找不到預設模板時拋出例外', function () {
    $article = createTestArticle();

    $this->service->generate($article);
})->throws(\RuntimeException::class, '找不到預設模板');

it('API 失敗時保留 filtered 狀態並拋出例外', function () {
    $template = createTestTemplate();
    $article = createTestArticle();

    $this->mockAiManager
        ->shouldReceive('driver')
        ->with('llm', 'generation')
        ->once()
        ->andReturn($this->mockLlm);

    $this->mockLlm
        ->shouldReceive('generateStructured')
        ->once()
        ->andThrow(new \RuntimeException('API 連線逾時'));

    try {
        $this->service->generate($article, $template);
    } catch (\RuntimeException) {
        // 預期的例外
    }

    $article->refresh();
    expect($article->status)->toBe('filtered');
    expect($article->summary)->toBeNull();
});

it('生成的 summary 為有效 JSON', function () {
    $template = createTestTemplate();
    $article = createTestArticle();

    $this->mockAiManager
        ->shouldReceive('driver')
        ->with('llm', 'generation')
        ->once()
        ->andReturn($this->mockLlm);

    $this->mockLlm
        ->shouldReceive('generateStructured')
        ->once()
        ->andReturn(mockLlmResponse());

    $result = $this->service->generate($article, $template);

    $decoded = json_decode($result->summary, true);
    expect($decoded)->toBeArray();
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
});

it('summary 中的中文字元未被轉義', function () {
    $template = createTestTemplate();
    $article = createTestArticle();

    $this->mockAiManager
        ->shouldReceive('driver')
        ->with('llm', 'generation')
        ->once()
        ->andReturn($this->mockLlm);

    $this->mockLlm
        ->shouldReceive('generateStructured')
        ->once()
        ->andReturn(mockLlmResponse());

    $result = $this->service->generate($article, $template);

    expect($result->summary)->toContain('測試摘要標題');
    expect($result->summary)->not->toContain('\u');
});

// --- regenerate ---

it('重新生成摘要並替換既有內容', function () {
    $template = createTestTemplate();
    $article = createTestArticle([
        'status' => 'generated',
        'template_id' => $template->id,
        'summary' => json_encode(['title' => '舊摘要'], JSON_UNESCAPED_UNICODE),
    ]);

    $newResponse = mockLlmResponse();
    $newResponse['title'] = '新摘要標題';

    $this->mockAiManager
        ->shouldReceive('driver')
        ->with('llm', 'generation')
        ->once()
        ->andReturn($this->mockLlm);

    $this->mockLlm
        ->shouldReceive('generateStructured')
        ->once()
        ->andReturn($newResponse);

    $result = $this->service->regenerate($article, $template);

    expect($result->status)->toBe('generated');
    $summary = json_decode($result->summary, true);
    expect($summary['title'])->toBe('新摘要標題');
});

it('regenerate 未指定模板時沿用文章原模板', function () {
    $template = createTestTemplate();
    $article = createTestArticle([
        'status' => 'generated',
        'template_id' => $template->id,
        'summary' => json_encode(['title' => '舊摘要'], JSON_UNESCAPED_UNICODE),
    ]);

    $this->mockAiManager
        ->shouldReceive('driver')
        ->with('llm', 'generation')
        ->once()
        ->andReturn($this->mockLlm);

    $this->mockLlm
        ->shouldReceive('generateStructured')
        ->once()
        ->andReturn(mockLlmResponse());

    $result = $this->service->regenerate($article);

    expect($result->status)->toBe('generated');
    expect($result->template_id)->toBe($template->id);
});

it('regenerate 可指定新模板', function () {
    $oldTemplate = createTestTemplate();
    $newTemplate = createTestTemplate([
        'slug' => 'deep-dive',
        'name' => '深度分析',
        'description' => '深入探討主題。',
        'output_structure' => [
            ['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true],
            ['field' => 'introduction', 'type' => 'text', 'label' => '導言', 'required' => true],
            ['field' => 'analysis_sections', 'type' => 'sections', 'label' => '分析段落', 'required' => true],
            ['field' => 'conclusion', 'type' => 'text', 'label' => '結論', 'required' => true],
        ],
    ]);

    $article = createTestArticle([
        'status' => 'generated',
        'template_id' => $oldTemplate->id,
        'summary' => json_encode(['title' => '舊摘要'], JSON_UNESCAPED_UNICODE),
    ]);

    $this->mockAiManager
        ->shouldReceive('driver')
        ->with('llm', 'generation')
        ->once()
        ->andReturn($this->mockLlm);

    $this->mockLlm
        ->shouldReceive('generateStructured')
        ->once()
        ->andReturn([
            'title' => '深度分析標題',
            'introduction' => '導言',
            'analysis_sections' => [['heading' => '段落一', 'content' => '內容']],
            'conclusion' => '結論',
        ]);

    $result = $this->service->regenerate($article, $newTemplate);

    expect($result->template_id)->toBe($newTemplate->id);
});

// --- buildGenerationPrompt ---

it('prompt 包含文章標題與內容', function () {
    $template = createTestTemplate();
    $article = createTestArticle([
        'title' => '科技新聞標題',
        'content_body' => '科技新聞的詳細內容。',
    ]);

    $prompt = $this->service->buildGenerationPrompt($article, $template);

    expect($prompt)->toContain('科技新聞標題');
    expect($prompt)->toContain('科技新聞的詳細內容。');
});

it('prompt 包含模板設定', function () {
    $template = createTestTemplate();
    $article = createTestArticle();

    $prompt = $this->service->buildGenerationPrompt($article, $template);

    expect($prompt)->toContain('informative');
    expect($prompt)->toContain('2000');
    expect($prompt)->toContain('5');
});

it('prompt 包含趨勢關鍵字', function () {
    $template = createTestTemplate();
    $article = createTestArticle([
        'trend_keywords' => ['人工智慧', '機器學習'],
    ]);

    $prompt = $this->service->buildGenerationPrompt($article, $template);

    expect($prompt)->toContain('人工智慧');
    expect($prompt)->toContain('機器學習');
});

it('prompt 在無趨勢關鍵字時不包含關鍵字區塊', function () {
    $template = createTestTemplate();
    $article = createTestArticle([
        'trend_keywords' => [],
    ]);

    $prompt = $this->service->buildGenerationPrompt($article, $template);

    expect($prompt)->not->toContain('相關趨勢關鍵字');
});

it('prompt 截斷過長的文章內容', function () {
    $template = createTestTemplate();
    $longContent = str_repeat('測', 5000);
    $article = createTestArticle([
        'content_body' => $longContent,
    ]);

    $prompt = $this->service->buildGenerationPrompt($article, $template);

    expect($prompt)->toContain('…（內容已截斷）');
});

it('prompt 處理空內容', function () {
    $template = createTestTemplate();
    $article = createTestArticle([
        'content_body' => '',
    ]);

    $prompt = $this->service->buildGenerationPrompt($article, $template);

    expect($prompt)->toContain('（無內容）');
});

it('prompt 包含輸出結構說明', function () {
    $template = createTestTemplate();
    $article = createTestArticle();

    $prompt = $this->service->buildGenerationPrompt($article, $template);

    expect($prompt)->toContain('`title`');
    expect($prompt)->toContain('`introduction`');
    expect($prompt)->toContain('`key_points`');
    expect($prompt)->toContain('必填');
});

// --- getGenerationSchema ---

it('schema 包含所有必填欄位', function () {
    $template = createTestTemplate();

    $schema = $this->service->getGenerationSchema($template);

    expect($schema['type'])->toBe('object');
    expect($schema['properties'])->toHaveKey('title');
    expect($schema['properties'])->toHaveKey('introduction');
    expect($schema['properties'])->toHaveKey('key_points');
    expect($schema['properties'])->toHaveKey('summary_paragraphs');
    expect($schema['properties'])->toHaveKey('conclusion');
    expect($schema['required'])->toContain('title');
    expect($schema['required'])->toContain('introduction');
    expect($schema['required'])->toContain('key_points');
});

it('schema 正確映射 string 類型', function () {
    $template = createTestTemplate([
        'output_structure' => [
            ['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true],
        ],
    ]);

    $schema = $this->service->getGenerationSchema($template);

    expect($schema['properties']['title']['type'])->toBe('string');
});

it('schema 正確映射 list 類型', function () {
    $template = createTestTemplate([
        'output_structure' => [
            ['field' => 'items', 'type' => 'list', 'label' => '清單', 'required' => true],
        ],
    ]);

    $schema = $this->service->getGenerationSchema($template);

    expect($schema['properties']['items']['type'])->toBe('array');
    expect($schema['properties']['items']['items']['type'])->toBe('string');
});

it('schema 正確映射 sections 類型', function () {
    $template = createTestTemplate([
        'output_structure' => [
            ['field' => 'sections', 'type' => 'sections', 'label' => '段落', 'required' => true],
        ],
    ]);

    $schema = $this->service->getGenerationSchema($template);

    expect($schema['properties']['sections']['type'])->toBe('array');
    expect($schema['properties']['sections']['items']['type'])->toBe('object');
    expect($schema['properties']['sections']['items']['properties'])->toHaveKey('heading');
    expect($schema['properties']['sections']['items']['properties'])->toHaveKey('content');
});

it('schema 正確映射 table 類型', function () {
    $template = createTestTemplate([
        'output_structure' => [
            ['field' => 'data', 'type' => 'table', 'label' => '表格', 'required' => false],
        ],
    ]);

    $schema = $this->service->getGenerationSchema($template);

    expect($schema['properties']['data']['type'])->toBe('array');
    expect($schema['required'])->not->toContain('data');
});

it('schema 區分必填與選填欄位', function () {
    $template = createTestTemplate([
        'output_structure' => [
            ['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true],
            ['field' => 'notes', 'type' => 'text', 'label' => '備註', 'required' => false],
        ],
    ]);

    $schema = $this->service->getGenerationSchema($template);

    expect($schema['required'])->toContain('title');
    expect($schema['required'])->not->toContain('notes');
});
