<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Contracts;

/**
 * 向量嵌入介面
 *
 * 定義文字向量嵌入的標準操作，用於將文字轉換為向量表示。
 * 宿主專案可透過 ServiceProvider 綁定自訂實作來覆寫預設行為。
 */
interface EmbeddingInterface
{
    /**
     * 將單一或多段文字轉換為向量嵌入
     *
     * @param  string|array<int, string>  $text  單一文字字串或文字陣列
     * @return array<int, float>  向量嵌入（浮點數陣列）
     */
    public function embed(string|array $text): array;

    /**
     * 批次將多段文字轉換為向量嵌入
     *
     * @param  array<int, string>  $texts  文字陣列
     * @return array<int, array<int, float>>  向量嵌入陣列，每個元素對應一段文字的向量
     */
    public function batchEmbed(array $texts): array;
}
