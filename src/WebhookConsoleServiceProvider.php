<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

use AmjadIqbal\WebhookConsole\Console\FailedCommand;
use AmjadIqbal\WebhookConsole\Console\ReplayCommand;
use AmjadIqbal\WebhookConsole\Http\ActionController;
use AmjadIqbal\WebhookConsole\Http\Authorize;
use AmjadIqbal\WebhookConsole\Http\DashboardController;
use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class WebhookConsoleServiceProvider extends ServiceProvider
{
    public const array GATES = ['viewWebhookConsole', 'replayWebhookCalls', 'pruneWebhookCalls'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webhook-console.php', 'webhook-console');
        $this->app->scoped(FailedJobs::class);
        $this->app->scoped(WebhookConsole::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'webhook-console');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/webhook-console.php' => config_path('webhook-console.php')], 'webhook-console-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/webhook-console')], 'webhook-console-views');
            $this->commands([FailedCommand::class, ReplayCommand::class]);
        }

        // Local only until the app defines its own gates (app providers boot after this one, so theirs win).
        foreach (self::GATES as $gate) {
            if (! Gate::has($gate)) {
                Gate::define($gate, fn ($user = null): bool => $this->app->environment('local'));
            }
        }

        if (config('webhook-console.dashboard.enabled')) {
            $this->registerRoutes();
        }

        AboutCommand::add('Webhook Console', fn (): array => [
            'Version' => self::version(),
            'Dashboard' => config('webhook-console.dashboard.enabled') ? '/'.trim((string) config('webhook-console.dashboard.path'), '/') : 'off',
            'Support' => 'https://amjad.com.pk/contact?utm_source=artisan&utm_medium=about&utm_campaign=laravel-webhook-console',
        ]);
    }

    private function registerRoutes(): void
    {
        $middleware = array_values(array_filter((array) config('webhook-console.dashboard.middleware', ['web']), 'is_string'));

        Route::group([
            'prefix' => trim((string) config('webhook-console.dashboard.path', 'webhook-console'), '/'),
            'domain' => config('webhook-console.dashboard.domain'),
            'middleware' => [...$middleware, Authorize::class],
        ], function (): void {
            Route::get('/', [DashboardController::class, 'index'])->name('webhook-console.index');
            Route::get('/calls/{id}', [DashboardController::class, 'show'])->name('webhook-console.show');
            Route::post('/calls/{id}/replay', [ActionController::class, 'replay'])->name('webhook-console.replay');
            Route::post('/retry', [ActionController::class, 'retry'])->name('webhook-console.retry');
            Route::post('/prune', [ActionController::class, 'prune'])->name('webhook-console.prune');
        });
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('amjadiqbal/laravel-webhook-console') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
