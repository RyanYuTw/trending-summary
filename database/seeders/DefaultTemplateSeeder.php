<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Database\Seeders;

use Illuminate\Database\Seeder;
use TnlMedia\TrendingSummary\Models\ArticleTemplate;

/**
 * 預設模板種子資料
 *
 * 種入 5 個內建文章模板，使用 updateOrCreate 確保冪等性。
 * 每個模板包含 slug、name、description、output_structure 與 settings。
 * 所有內建模板標記 is_builtin = true，受刪除保護。
 */
class DefaultTemplateSeeder extends Seeder
{
    /**
     * 執行種子資料填入。
     */
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            ArticleTemplate::updateOrCreate(
                ['slug' => $template['slug']],
                [
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'is_builtin' => true,
                    'output_structure' => $template['output_structure'],
                    'settings' => $template['settings'],
                ],
            );
        }
    }

    /**
     * 取得所有內建模板定義。
     *
     * @return list<array{
     *     slug: string,
     *     name: string,
     *     description: string,
     *     output_structure: list<array{field: string, type: string, label: string, required: bool}>,
     *     settings: array{tone: string, use_emoji: bool, max_paragraphs: int, max_total_chars: int},
     * }>
     */
    private function templates(): array
    {
        return [
            $this->trendingSummary(),
            $this->deepDive(),
            $this->listicle(),
            $this->comparison(),
            $this->breakingNews(),
        ];
    }

    /**
     * 趨勢摘要模板：快速摘要當前趨勢文章的重點。
     *
     * @return array<string, mixed>
     */
    private function trendingSummary(): array
    {
        return [
            'slug' => 'trending-summary',
            'name' => '趨勢摘要',
            'description' => '快速摘要當前趨勢文章的重點，適合需要快速掌握趨勢動態的讀者。',
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
        ];
    }

    /**
     * 深度分析模板：深入探討主題的詳細分析文。
     *
     * @return array<string, mixed>
     */
    private function deepDive(): array
    {
        return [
            'slug' => 'deep-dive',
            'name' => '深度分析',
            'description' => '深入探討主題的詳細分析文，適合需要全面了解議題背景與影響的讀者。',
            'output_structure' => [
                ['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true],
                ['field' => 'introduction', 'type' => 'text', 'label' => '導言', 'required' => true],
                ['field' => 'analysis_sections', 'type' => 'sections', 'label' => '分析段落', 'required' => true],
                ['field' => 'data_points', 'type' => 'list', 'label' => '關鍵數據', 'required' => false],
                ['field' => 'conclusion', 'type' => 'text', 'label' => '結論', 'required' => true],
                ['field' => 'references', 'type' => 'list', 'label' => '參考來源', 'required' => false],
            ],
            'settings' => [
                'tone' => 'analytical',
                'use_emoji' => false,
                'max_paragraphs' => 10,
                'max_total_chars' => 5000,
            ],
        ];
    }

    /**
     * 清單體模板：以條列方式呈現重點的清單文章。
     *
     * @return array<string, mixed>
     */
    private function listicle(): array
    {
        return [
            'slug' => 'listicle',
            'name' => '清單體',
            'description' => '以條列方式呈現重點的清單文章，適合快速瀏覽與分享。',
            'output_structure' => [
                ['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true],
                ['field' => 'introduction', 'type' => 'text', 'label' => '導言', 'required' => true],
                ['field' => 'list_items', 'type' => 'numbered_list', 'label' => '清單項目', 'required' => true],
                ['field' => 'conclusion', 'type' => 'text', 'label' => '結語', 'required' => true],
            ],
            'settings' => [
                'tone' => 'engaging',
                'use_emoji' => true,
                'max_paragraphs' => 8,
                'max_total_chars' => 3000,
            ],
        ];
    }

    /**
     * 比較分析模板：比較多個觀點或方案的分析文。
     *
     * @return array<string, mixed>
     */
    private function comparison(): array
    {
        return [
            'slug' => 'comparison',
            'name' => '比較分析',
            'description' => '比較多個觀點或方案的分析文，適合需要權衡利弊做出決策的讀者。',
            'output_structure' => [
                ['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true],
                ['field' => 'introduction', 'type' => 'text', 'label' => '導言', 'required' => true],
                ['field' => 'comparison_items', 'type' => 'sections', 'label' => '比較項目', 'required' => true],
                ['field' => 'pros_cons', 'type' => 'table', 'label' => '優缺點對照', 'required' => true],
                ['field' => 'verdict', 'type' => 'text', 'label' => '總結評比', 'required' => true],
            ],
            'settings' => [
                'tone' => 'objective',
                'use_emoji' => false,
                'max_paragraphs' => 8,
                'max_total_chars' => 4000,
            ],
        ];
    }

    /**
     * 即時快訊模板：快速報導突發新聞的簡短摘要。
     *
     * @return array<string, mixed>
     */
    private function breakingNews(): array
    {
        return [
            'slug' => 'breaking-news',
            'name' => '即時快訊',
            'description' => '快速報導突發新聞的簡短摘要，適合第一時間掌握突發事件的讀者。',
            'output_structure' => [
                ['field' => 'title', 'type' => 'string', 'label' => '標題', 'required' => true],
                ['field' => 'lead_paragraph', 'type' => 'text', 'label' => '導言段落', 'required' => true],
                ['field' => 'key_facts', 'type' => 'list', 'label' => '關鍵事實', 'required' => true],
                ['field' => 'developing_info', 'type' => 'text', 'label' => '後續發展', 'required' => false],
            ],
            'settings' => [
                'tone' => 'urgent',
                'use_emoji' => false,
                'max_paragraphs' => 3,
                'max_total_chars' => 1000,
            ],
        ];
    }
}
