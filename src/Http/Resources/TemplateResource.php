<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 模板 API Resource
 *
 * 格式化 ArticleTemplate 模型的 JSON 輸出。
 */
class TemplateResource extends JsonResource
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
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'is_builtin' => $this->is_builtin,
            'output_structure' => $this->output_structure,
            'settings' => $this->settings,
        ];
    }
}
