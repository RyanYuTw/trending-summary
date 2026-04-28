<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Contracts;

use TnlMedia\TrendingSummary\DataTransferObjects\PublishResult;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * 發佈目標介面
 *
 * 定義文章發佈至外部平台的標準操作，支援發佈、更新、連線檢查等功能。
 * 每個目標平台（如 InkMagine CMS、WordPress）各自實作此介面。
 * 宿主專案可透過 ServiceProvider 綁定自訂實作來新增發佈目標。
 */
interface PublisherInterface
{
    /**
     * 將文章發佈至目標平台
     *
     * @param  TrendingArticle  $article  待發佈的文章
     * @param  array<string, mixed>  $options  額外選項（如 status: 'draft'|'published'）
     * @return PublishResult  發佈結果，包含遠端文章 ID 與 URL
     */
    public function publish(TrendingArticle $article, array $options = []): PublishResult;

    /**
     * 更新已發佈至目標平台的文章
     *
     * @param  TrendingArticle  $article  已修改的文章
     * @param  string  $externalId  遠端平台的文章 ID
     * @return PublishResult  更新結果
     */
    public function update(TrendingArticle $article, string $externalId): PublishResult;

    /**
     * 檢查與目標平台的連線是否正常
     *
     * @return bool  連線正常回傳 true，否則回傳 false
     */
    public function ping(): bool;

    /**
     * 取得此發佈器對應的平台名稱
     *
     * @return string  平台識別名稱（如 'inkmagine'、'wordpress'）
     */
    public function platform(): string;
}
