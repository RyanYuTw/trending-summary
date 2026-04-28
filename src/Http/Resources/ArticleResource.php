<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 文章 API Resource
 *
 * 格式化 TrendingArticle 模型的 JSON 輸出，
 * 包含基本欄位與條件性載入的關聯資料。
 */
class ArticleResource extends JsonResource
{
    /**
     * 將資源轉換為陣列。
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'original_title' => $this->original_title,
            'original_url' => $this->original_url,
            'source_name' => $this->source_name,
            'content_type' => $this->content_type,
            'summary' => $this->summary,
            'selected_title' => $this->selected_title,
            'status' => $this->status,
            'relevance_score' => $this->relevance_score,
            'quality_score' => $this->quality_score,
            'is_auto_published' => $this->is_auto_published,
            'trend_keywords' => $this->trend_keywords,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // 條件性載入的關聯資料
            'generated_titles' => $this->whenLoaded('generatedTitles'),
            'image' => $this->whenLoaded('image'),
            'subtitle' => $this->whenLoaded('subtitle'),
            'seo' => $this->whenLoaded('seo'),
            'quality_report' => $this->whenLoaded('qualityReport'),
            'publish_records' => $this->whenLoaded('publishRecords'),
            'template' => $this->whenLoaded('template', fn () => new TemplateResource($this->template)),
        ];
    }
}
