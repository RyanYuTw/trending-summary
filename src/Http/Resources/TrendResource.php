<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 趨勢關鍵字 API Resource
 *
 * 格式化 TrendKeyword 模型的 JSON 輸出。
 */
class TrendResource extends JsonResource
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
            'keyword' => $this->keyword,
            'traffic_volume' => $this->traffic_volume,
            'trend_date' => $this->trend_date?->toDateString(),
        ];
    }
}
