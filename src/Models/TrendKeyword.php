<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 趨勢關鍵字模型
 *
 * 儲存從 Google Trends 同步的搜尋趨勢關鍵字，
 * 包含搜尋量與趨勢日期，用於文章相關性篩選。
 */
class TrendKeyword extends Model
{
    /**
     * 取得含可設定前綴的資料表名稱。
     */
    public function getTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'trend_keywords';
    }

    /**
     * 可批量賦值的欄位。
     *
     * @var list<string>
     */
    protected $fillable = [
        'keyword',
        'traffic_volume',
        'trend_date',
    ];

    /**
     * 屬性型別轉換。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'traffic_volume' => 'integer',
            'trend_date' => 'date',
        ];
    }
}
