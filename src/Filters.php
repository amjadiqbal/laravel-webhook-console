<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class Filters
{
    public const array STATUSES = ['', 'failures', 'failed', 'queue_failed', 'ok'];

    public function __construct(
        public ?string $name = null,
        public string $status = '',
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?string $search = null,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown status '{$status}'. Use one of: failures, failed, queue_failed, ok.");
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $status = (string) ($input['status'] ?? '');

        return new self(
            name: self::str($input['name'] ?? null),
            status: in_array($status, self::STATUSES, true) ? $status : '',
            from: self::date($input['from'] ?? null),
            to: self::date($input['to'] ?? null, endOfDay: true),
            search: self::str($input['q'] ?? null),
        );
    }

    /**
     * "2h", "3d", "1w", "30m" (ago), or anything Carbon can parse.
     */
    public static function date(mixed $value, bool $endOfDay = false): ?CarbonImmutable
    {
        $value = self::str($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d+)\s*([mhdw])$/i', $value, $m) === 1) {
            $unit = ['m' => 'minutes', 'h' => 'hours', 'd' => 'days', 'w' => 'weeks'][strtolower($m[2])];

            return CarbonImmutable::now()->sub($unit, (int) $m[1]);
        }

        try {
            $date = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        // A bare date as the upper bound means "through the end of that day".
        return $endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $date->endOfDay() : $date;
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'name' => $this->name ?? '',
            'status' => $this->status,
            'from' => $this->from?->format('Y-m-d') ?? '',
            'to' => $this->to?->format('Y-m-d') ?? '',
            'q' => $this->search ?? '',
        ], fn (string $v): bool => $v !== '');
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
