<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Contracts;

/**
 * 圖片來源介面
 *
 * 定義圖片搜尋與取得的標準操作，用於文章配圖功能。
 * 宿主專案可透過 ServiceProvider 綁定自訂實作來覆寫預設行為。
 */
interface ImageProviderInterface
{
    /**
     * 依關鍵字搜尋圖片
     *
     * @param  string  $query  搜尋關鍵字
     * @param  array<string, mixed>  $options  額外選項（如 limit、offset、size 等）
     * @return array<int, array<string, mixed>>  圖片結果陣列，每個元素包含 url、thumbnail_url、caption 等資訊
     */
    public function search(string $query, array $options = []): array;

    /**
     * 依 ID 取得單一圖片資訊
     *
     * @param  string  $id  圖片識別碼
     * @return array<string, mixed>|null  圖片資訊（含 url、thumbnail_url、caption、versions 等），找不到時回傳 null
     */
    public function getById(string $id): ?array;
}
