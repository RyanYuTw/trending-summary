<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use TnlMedia\TrendingSummary\Console\FetchAndFilterCommand;
use TnlMedia\TrendingSummary\Console\SyncTrendingCommand;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Contracts\EmbeddingInterface;
use TnlMedia\TrendingSummary\Contracts\ImageProviderInterface;
use TnlMedia\TrendingSummary\Contracts\LlmInterface;
use TnlMedia\TrendingSummary\Contracts\PublisherInterface;
use TnlMedia\TrendingSummary\Contracts\TranslatorInterface;
use TnlMedia\TrendingSummary\Services\AiModelManager;
use TnlMedia\TrendingSummary\Services\ImageSourceService;
use TnlMedia\TrendingSummary\Services\InkMaginePublisher;
use TnlMedia\TrendingSummary\Services\WordPressPublisher;

class TrendingSummaryServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/trending-summary.php',
            'trending-summary'
        );

        $this->app->singleton(AiModelManagerInterface::class, AiModelManager::class);
        $this->app->alias(AiModelManagerInterface::class, 'trending-summary');

        $this->app->bind(EmbeddingInterface::class, function ($app) {
            return $app->make(AiModelManagerInterface::class)->driver('embedding');
        });

        $this->app->bind(LlmInterface::class, function ($app) {
            return $app->make(AiModelManagerInterface::class)->driver('llm');
        });

        $this->app->bind(TranslatorInterface::class, function ($app) {
            return $app->make(AiModelManagerInterface::class)->driver('translation');
        });

        $this->app->bind(ImageProviderInterface::class, ImageSourceService::class);

        $this->app->tag([InkMaginePublisher::class, WordPressPublisher::class], PublisherInterface::class);
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'trending-summary');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncTrendingCommand::class,
                FetchAndFilterCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/trending-summary.php' => config_path('trending-summary.php'),
            ], 'trending-summary-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'trending-summary-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/trending-summary'),
            ], 'trending-summary-views');

            $this->publishes([
                __DIR__ . '/../dist' => public_path('vendor/trending-summary'),
            ], 'trending-summary-assets');
        }

        // 排程註冊：依設定檔決定是否自動排程 articles:sync-trending
        if (config('trending-summary.schedule.enabled', false)) {
            $this->app->booted(function () {
                /** @var Schedule $schedule */
                $schedule = $this->app->make(Schedule::class);
                $frequency = config('trending-summary.schedule.frequency', 'hourly');
                $command = $schedule->command('articles:sync-trending');

                match ($frequency) {
                    'hourly' => $command->hourly(),
                    'every_two_hours' => $command->everyTwoHours(),
                    'daily' => $command->daily(),
                    default => $command->hourly(),
                };
            });
        }
    }
}
