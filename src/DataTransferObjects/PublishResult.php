<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\DataTransferObjects;

/**
 * 發佈結果資料傳輸物件
 *
 * 封裝文章發佈至目標平台後的回傳結果，包含成功/失敗狀態、
 * 遠端文章 ID 與 URL，以及錯誤訊息。
 */
final readonly class PublishResult
{
    public function __construct(
        /** 是否發佈成功 */
        public bool $success,
        /** 遠端平台的文章 ID（發佈成功時提供） */
        public ?string $externalId = null,
        /** 遠端平台的文章 URL（發佈成功時提供） */
        public ?string $externalUrl = null,
        /** 錯誤訊息（發佈失敗時提供） */
        public ?string $errorMessage = null,
    ) {}

    /**
     * 建立成功的發佈結果
     */
    public static function success(string $externalId, ?string $externalUrl = null): self
    {
        return new self(
            success: true,
            externalId: $externalId,
            externalUrl: $externalUrl,
        );
    }

    /**
     * 建立失敗的發佈結果
     */
    public static function failure(string $errorMessage): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
        );
    }
}
