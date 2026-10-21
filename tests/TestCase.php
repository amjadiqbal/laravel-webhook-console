<?php

namespace AmjadIqbal\WebhookConsole\Tests;

use AmjadIqbal\WebhookConsole\Tests\Fixtures\RecordingJob;
use AmjadIqbal\WebhookConsole\WebhookConsoleServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\SignatureValidator\DefaultSignatureValidator;
use Spatie\WebhookClient\WebhookClientServiceProvider;
use Spatie\WebhookClient\WebhookProfile\ProcessEverythingWebhookProfile;
use Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WebhookClientServiceProvider::class, WebhookConsoleServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');

        // scripts/integration.sh runs this suite against real MySQL and PostgreSQL servers too.
        if (in_array(getenv('WC_DB'), ['mysql', 'pgsql'], true)) {
            $app['config']->set('database.connections.testing', [
                'driver' => getenv('WC_DB'),
                'host' => '127.0.0.1',
                'port' => (int) getenv('WC_DB_PORT'),
                'database' => 'console',
                'username' => getenv('WC_DB') === 'mysql' ? 'root' : 'postgres',
                'password' => 'secret',
                'charset' => getenv('WC_DB') === 'mysql' ? 'utf8mb4' : 'utf8',
                'prefix' => '',
            ]);
        }
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs']);
        $app['config']->set('webhook-client.configs', [
            self::endpoint('default', RecordingJob::class),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function endpoint(string $name, string $job, string $model = WebhookCall::class): array
    {
        return [
            'name' => $name,
            'signing_secret' => 'secret',
            'signature_header_name' => 'Signature',
            'signature_validator' => DefaultSignatureValidator::class,
            'webhook_profile' => ProcessEverythingWebhookProfile::class,
            'webhook_response' => DefaultRespondsTo::class,
            'webhook_model' => $model,
            'store_headers' => '*',
            'process_webhook_job' => $job,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['webhook_calls', 'custom_webhook_calls', 'jobs', 'failed_jobs'] as $table) {
            Schema::dropIfExists($table);
        }

        $stub = require __DIR__.'/../vendor/spatie/laravel-webhook-client/database/migrations/create_webhook_calls_table.php.stub';
        $stub->up();

        Schema::create('custom_webhook_calls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('url', 512);
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->text('exception')->nullable();
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    protected function defineRoutes($router): void
    {
        Route::webhooks('webhooks/default', 'default');
        Route::webhooks('webhooks/other', 'other');
    }

    /**
     * Sends a webhook the way a provider does: raw JSON body, HMAC-SHA256 "Signature" header.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function sendWebhook(array $payload, string $endpoint = 'default', array $headers = []): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', "/webhooks/{$endpoint}", [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'Signature' => hash_hmac('sha256', $body, 'secret'),
            ...$headers,
        ]), $body);
    }

    /** Local, signed in, and allowed to replay and prune (both gates deny by default). */
    protected function asOperator(): void
    {
        $this->asLocal();
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Operator']));
        Gate::define('replayWebhookCalls', fn ($user) => true);
        Gate::define('pruneWebhookCalls', fn ($user) => true);
    }

    /** Local environment; CSRF is then enforced like in a real app, so these tests opt out of it. */
    protected function asLocal(): void
    {
        $this->app['env'] = 'local';
        // Laravel 12 ValidateCsrfToken, Laravel 13 PreventRequestForgery.
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
        ]);
    }
}
