<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Facades;

use Illuminate\Support\Facades\Facade;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;

/**
 * @method static mixed driver(string $role, ?string $subRole = null)
 * @method static array getConfig(string $role, ?string $subRole = null)
 *
 * @see \TnlMedia\TrendingSummary\Services\AiModelManager
 */
class TrendingSummary extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return AiModelManagerInterface::class;
    }
}
