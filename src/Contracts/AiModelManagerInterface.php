<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Contracts;

/**
 * AI 模型管理器介面
 *
 * 負責依角色設定動態解析 AI driver 與 model。每個角色（embedding、llm、translation）
 * 可獨立設定 driver 與 model，LLM 角色下的子角色（relevance、generation、title、seo、quality）
 * 可進一步覆寫父角色的設定。宿主專案可透過 ServiceProvider 綁定自訂實作來覆寫預設行為。
 */
interface AiModelManagerInterface
{
    /**
     * 取得指定角色的 AI driver 實例
     *
     * @param  string  $role  角色名稱：embedding、llm、translation
     * @param  string|null  $subRole  子角色名稱：relevance、generation、title、seo、quality（僅 llm 角色適用）
     * @return mixed  對應角色的 driver 實例（實作 EmbeddingInterface、LlmInterface 或 TranslatorInterface）
     */
    public function driver(string $role, ?string $subRole = null): mixed;

    /**
     * 取得指定角色的模型設定
     *
     * 當指定子角色時，子角色的設定會覆寫父角色的預設值；
     * 未指定的設定項目則 fallback 至父角色的預設值。
     *
     * @param  string  $role  角色名稱：embedding、llm、translation
     * @param  string|null  $subRole  子角色名稱（可選）
     * @return array<string, mixed>  模型設定（含 driver、model、api_key 等）
     */
    public function getConfig(string $role, ?string $subRole = null): array;
}
