<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use TnlMedia\TrendingSummary\Models\ArticleTemplate;
use TnlMedia\TrendingSummary\Services\ArticleTemplateService;

beforeEach(function () {
    $this->service = new ArticleTemplateService();
});

/**
 * 建立有效的模板資料。
 */
function validTemplateData(array $overrides = []): array
{
    return array_merge([
        'slug' => 'test-template',
        'name' => '測試模板',
        'description' => '這是一個測試用的自訂模板。',
        'output_structure' => [
            ['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true],
            ['field' => 'body', 'type' => 'text', 'label' => '內文', 'required' => true],
        ],
        'settings' => [
            'tone' => 'informative',
            'use_emoji' => false,
            'max_paragraphs' => 5,
            'max_total_chars' => 2000,
        ],
    ], $overrides);
}

// --- list ---

it('列出所有模板，內建模板排在前面', function () {
    ArticleTemplate::create([
        'slug' => 'builtin-one',
        'name' => '內建模板',
        'description' => '內建',
        'is_builtin' => true,
        'output_structure' => [['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true]],
        'settings' => ['tone' => 'informative', 'use_emoji' => false, 'max_paragraphs' => 5, 'max_total_chars' => 2000],
    ]);

    ArticleTemplate::create([
        'slug' => 'custom-one',
        'name' => '自訂模板',
        'description' => '自訂',
        'is_builtin' => false,
        'output_structure' => [['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true]],
        'settings' => ['tone' => 'engaging', 'use_emoji' => true, 'max_paragraphs' => 3, 'max_total_chars' => 1000],
    ]);

    $result = $this->service->list();

    expect($result)->toHaveCount(2);
    expect($result->first()->is_builtin)->toBeTrue();
    expect($result->last()->is_builtin)->toBeFalse();
});

it('無模板時回傳空集合', function () {
    $result = $this->service->list();

    expect($result)->toBeEmpty();
});

// --- getBySlug ---

it('依 slug 取得模板', function () {
    ArticleTemplate::create(validTemplateData());

    $result = $this->service->getBySlug('test-template');

    expect($result)->not->toBeNull();
    expect($result->slug)->toBe('test-template');
    expect($result->name)->toBe('測試模板');
});

it('slug 不存在時回傳 null', function () {
    $result = $this->service->getBySlug('non-existent');

    expect($result)->toBeNull();
});

// --- getById ---

it('依 ID 取得模板', function () {
    $template = ArticleTemplate::create(validTemplateData());

    $result = $this->service->getById($template->id);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($template->id);
});

it('ID 不存在時回傳 null', function () {
    $result = $this->service->getById(99999);

    expect($result)->toBeNull();
});

// --- create ---

it('建立自訂模板', function () {
    $data = validTemplateData();

    $template = $this->service->create($data);

    expect($template)->toBeInstanceOf(ArticleTemplate::class);
    expect($template->slug)->toBe('test-template');
    expect($template->name)->toBe('測試模板');
    expect($template->description)->toBe('這是一個測試用的自訂模板。');
    expect($template->is_builtin)->toBeFalse();
    expect($template->output_structure)->toBeArray();
    expect($template->output_structure)->toHaveCount(2);
    expect($template->settings)->toBeArray();
    expect($template->settings['tone'])->toBe('informative');
});

it('建立模板時 is_builtin 固定為 false', function () {
    $data = validTemplateData(['is_builtin' => true]);

    $template = $this->service->create($data);

    expect($template->is_builtin)->toBeFalse();
});

it('slug 重複時驗證失敗', function () {
    ArticleTemplate::create(validTemplateData());

    $this->service->create(validTemplateData());
})->throws(ValidationException::class);

it('slug 非 kebab-case 時驗證失敗', function () {
    $this->service->create(validTemplateData(['slug' => 'Invalid Slug']));
})->throws(ValidationException::class);

it('slug 含大寫字母時驗證失敗', function () {
    $this->service->create(validTemplateData(['slug' => 'MyTemplate']));
})->throws(ValidationException::class);

it('缺少必填欄位時驗證失敗', function () {
    $this->service->create(['slug' => 'test']);
})->throws(ValidationException::class);

it('output_structure 為空陣列時驗證失敗', function () {
    $this->service->create(validTemplateData(['output_structure' => []]));
})->throws(ValidationException::class);

it('output_structure 項目缺少必要欄位時驗證失敗', function () {
    $this->service->create(validTemplateData([
        'output_structure' => [
            ['field' => 'title'],
        ],
    ]));
})->throws(ValidationException::class);

it('settings 缺少必要欄位時驗證失敗', function () {
    $this->service->create(validTemplateData([
        'settings' => ['tone' => 'informative'],
    ]));
})->throws(ValidationException::class);

// --- update ---

it('更新自訂模板', function () {
    $template = $this->service->create(validTemplateData());

    $updated = $this->service->update($template, validTemplateData([
        'slug' => 'updated-slug',
        'name' => '更新後的模板',
    ]));

    expect($updated->slug)->toBe('updated-slug');
    expect($updated->name)->toBe('更新後的模板');
});

it('更新時可保留原 slug', function () {
    $template = $this->service->create(validTemplateData());

    $updated = $this->service->update($template, validTemplateData([
        'name' => '只改名稱',
    ]));

    expect($updated->slug)->toBe('test-template');
    expect($updated->name)->toBe('只改名稱');
});

it('更新時 slug 與其他模板衝突會驗證失敗', function () {
    $this->service->create(validTemplateData(['slug' => 'first-template']));
    $second = $this->service->create(validTemplateData(['slug' => 'second-template']));

    $this->service->update($second, validTemplateData(['slug' => 'first-template']));
})->throws(ValidationException::class);

// --- delete ---

it('刪除自訂模板', function () {
    $template = $this->service->create(validTemplateData());

    $result = $this->service->delete($template);

    expect($result)->toBeTrue();
    expect(ArticleTemplate::find($template->id))->toBeNull();
});

it('刪除內建模板時拋出例外', function () {
    $template = ArticleTemplate::create(validTemplateData([
        'slug' => 'builtin-template',
        'is_builtin' => true,
    ]));

    $this->service->delete($template);
})->throws(\RuntimeException::class, '內建模板不可刪除。');

it('刪除內建模板後模板仍存在', function () {
    $template = ArticleTemplate::create(validTemplateData([
        'slug' => 'builtin-template',
        'is_builtin' => true,
    ]));

    try {
        $this->service->delete($template);
    } catch (\RuntimeException) {
        // 預期的例外
    }

    expect(ArticleTemplate::find($template->id))->not->toBeNull();
});

// --- validate ---

it('驗證有效資料通過', function () {
    $data = validTemplateData();

    $validated = $this->service->validate($data);

    expect($validated['slug'])->toBe('test-template');
    expect($validated['name'])->toBe('測試模板');
    expect($validated['output_structure'])->toHaveCount(2);
    expect($validated['settings'])->toHaveKey('tone');
});

it('slug 接受純數字 kebab-case', function () {
    $data = validTemplateData(['slug' => 'template-123']);

    $validated = $this->service->validate($data);

    expect($validated['slug'])->toBe('template-123');
});

it('slug 不接受底線', function () {
    $this->service->validate(validTemplateData(['slug' => 'test_template']));
})->throws(ValidationException::class);

it('slug 不接受連續連字號', function () {
    $this->service->validate(validTemplateData(['slug' => 'test--template']));
})->throws(ValidationException::class);

it('slug 不接受開頭連字號', function () {
    $this->service->validate(validTemplateData(['slug' => '-test']));
})->throws(ValidationException::class);

it('slug 不接受結尾連字號', function () {
    $this->service->validate(validTemplateData(['slug' => 'test-']));
})->throws(ValidationException::class);
