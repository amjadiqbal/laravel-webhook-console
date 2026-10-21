<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * One entry of webhook-client's "configs" array, read as plain values. Reading the raw array
 * (instead of building Spatie's WebhookConfig) means a half-finished config, such as the
 * published default with an empty process_webhook_job, never breaks the dashboard.
 */
final readonly class Endpoint
{
    /**
     * @param  class-string<WebhookCall>  $modelClass
     */
    public function __construct(
        public string $name,
        public string $modelClass,
        public string $jobClass,
        public string $signatureHeader,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $model = $config['webhook_model'] ?? null;

        return new self(
            name: (string) ($config['name'] ?? 'default'),
            modelClass: is_string($model) && is_a($model, WebhookCall::class, true) ? $model : WebhookCall::class,
            jobClass: is_string($config['process_webhook_job'] ?? null) ? $config['process_webhook_job'] : '',
            signatureHeader: is_string($config['signature_header_name'] ?? null) ? $config['signature_header_name'] : '',
        );
    }

    /** Why this endpoint cannot replay calls, or null when it can. */
    public function replayProblem(): ?string
    {
        if ($this->jobClass === '') {
            return "The '{$this->name}' webhook-client config has no process_webhook_job.";
        }

        if (! class_exists($this->jobClass)) {
            return "The process job {$this->jobClass} does not exist.";
        }

        if (! is_subclass_of($this->jobClass, ProcessWebhookJob::class)) {
            return "The process job {$this->jobClass} does not extend ".ProcessWebhookJob::class.'.';
        }

        return null;
    }

    public function table(): string
    {
        return (new $this->modelClass)->getTable();
    }
}
