<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use InvalidArgumentException;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Services\Drivers\AnthropicDriver;
use TnlMedia\TrendingSummary\Services\Drivers\GeminiDriver;
use TnlMedia\TrendingSummary\Services\Drivers\OpenAiCompatibleDriver;
use TnlMedia\TrendingSummary\Services\Drivers\OpenAiDriver;

/**
 * AI 模型管理器
 *
 * 負責依角色設定動態解析 AI driver 與 model。每個角色（embedding、llm、translation）
 * 可獨立設定 driver 與 model，LLM 角色下的子角色（relevance、generation、title、seo、quality）
 * 可進一步覆寫父角色的設定。已解析的 driver 實例會被快取以避免重複建立。
 */
class AiModelManager implements AiModelManagerInterface
{
    /**
     * 已解析的 driver 實例快取
     *
     * @var array<string, mixed>
     */
    protected array $resolvedDrivers = [];

    /**
     * 支援的 driver 名稱與對應類別映射
     *
     * @var array<string, class-string>
     */
    protected array $driverMap = [
        'gemini' => GeminiDriver::class,
        'openai' => OpenAiDriver::class,
        'anthropic' => AnthropicDriver::class,
        'openai-compatible' => OpenAiCompatibleDriver::class,
        'openai_compatible' => OpenAiCompatibleDriver::class,
    ];

    /**
     * 支援的角色名稱
     *
     * @var array<int, string>
     */
    protected array $validRoles = ['embedding', 'llm', 'translation'];

    /**
     * 取得指定角色的 AI driver 實例
     *
     * 依據角色與子角色設定解析對應的 driver 類別並建立實例。
     * 已建立的實例會被快取，相同的 role + subRole 組合不會重複建立。
     *
     * @param  string  $role  角色名稱：embedding、llm、translation
     * @param  string|null  $subRole  子角色名稱：relevance、generation、title、seo、quality（僅 llm 角色適用）
     * @return mixed  對應角色的 driver 實例
     *
     * @throws InvalidArgumentException  當角色名稱無效或 driver 名稱無法解析時
     */
    public function driver(string $role, ?string $subRole = null): mixed
    {
        $cacheKey = $role . ($subRole !== null ? ".{$subRole}" : '');

        if (isset($this->resolvedDrivers[$cacheKey])) {
            return $this->resolvedDrivers[$cacheKey];
        }

        $config = $this->getConfig($role, $subRole);
        $driverName = $config['driver'] ?? null;

        if ($driverName === null) {
            throw new InvalidArgumentException("未設定角色 [{$role}] 的 driver。");
        }

        $driverClass = $this->resolveDriverClass($driverName);

        $driverConfig = $this->mergeDriverConnectionConfig($driverName, $config);

        $instance = new $driverClass($driverConfig);

        $this->resolvedDrivers[$cacheKey] = $instance;

        return $instance;
    }

    /**
     * 取得指定角色的模型設定
     *
     * 當指定子角色時，子角色的設定會覆寫父角色的預設值；
     * 未指定的設定項目則 fallback 至父角色的預設值。
     *
     * @param  string  $role  角色名稱：embedding、llm、translation
     * @param  string|null  $subRole  子角色名稱（可選）
     * @return array<string, mixed>  模型設定（含 driver、model、api_key 等）
     *
     * @throws InvalidArgumentException  當角色名稱無效時
     */
    public function getConfig(string $role, ?string $subRole = null): array
    {
        $this->validateRole($role);

        /** @var array<string, mixed> $roleConfig */
        $roleConfig = config("trending-summary.ai.{$role}", []);

        // 移除 roles（子角色定義）以取得純粹的父角色設定
        $parentConfig = collect($roleConfig)->except('roles')->all();

        if ($subRole === null) {
            return $parentConfig;
        }

        // 取得子角色設定，覆寫父角色預設值
        /** @var array<string, mixed> $subRoleConfig */
        $subRoleConfig = $roleConfig['roles'][$subRole] ?? [];

        // 子角色設定覆寫父角色，未指定的項目 fallback 至父角色
        return array_merge($parentConfig, $subRoleConfig);
    }

    /**
     * 驗證角色名稱是否有效
     *
     * @param  string  $role  角色名稱
     *
     * @throws InvalidArgumentException  當角色名稱無效時
     */
    protected function validateRole(string $role): void
    {
        if (! in_array($role, $this->validRoles, true)) {
            throw new InvalidArgumentException(
                "無效的角色名稱 [{$role}]，支援的角色：" . implode('、', $this->validRoles)
            );
        }
    }

    /**
     * 解析 driver 名稱對應的類別
     *
     * @param  string  $driverName  driver 名稱
     * @return class-string  driver 類別名稱
     *
     * @throws InvalidArgumentException  當 driver 名稱無法解析時
     */
    protected function resolveDriverClass(string $driverName): string
    {
        if (isset($this->driverMap[$driverName])) {
            return $this->driverMap[$driverName];
        }

        throw new InvalidArgumentException(
            "無法解析 driver [{$driverName}]，支援的 driver：" . implode('、', array_keys($this->driverMap))
        );
    }

    /**
     * 合併 driver 連線設定與角色設定
     *
     * 將全域 driver 連線設定（api_key、base_url、timeout）與角色設定合併，
     * 角色設定中的值優先於全域 driver 設定。
     *
     * @param  string  $driverName  driver 名稱
     * @param  array<string, mixed>  $roleConfig  角色設定
     * @return array<string, mixed>  合併後的完整設定
     */
    protected function mergeDriverConnectionConfig(string $driverName, array $roleConfig): array
    {
        /** @var array<string, mixed> $driverConnectionConfig */
        $driverConnectionConfig = config("trending-summary.ai.drivers.{$driverName}", []);

        // 角色設定優先於全域 driver 連線設定
        return array_merge($driverConnectionConfig, $roleConfig);
    }
}
