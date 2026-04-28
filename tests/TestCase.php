<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;
use TnlMedia\TrendingSummary\TrendingSummaryServiceProvider;

/**
 * 套件測試基底類別
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * 取得套件的 ServiceProvider。
     *
     * @param \Illuminate\Foundation\Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TrendingSummaryServiceProvider::class,
        ];
    }

    /**
     * 定義測試環境設定。
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('trending-summary.table_prefix', 'ts_');
    }

    /**
     * 忽略尚未建立的 Console Command 類別。
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function resolveApplicationConsoleKernel($app): void
    {
        // 使用預設 Kernel，避免載入尚未建立的 Artisan Commands
        $app->singleton(
            \Illuminate\Contracts\Console\Kernel::class,
            \Orchestra\Testbench\Console\Kernel::class,
        );
    }
}
