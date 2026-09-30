<?php

declare(strict_types=1);

namespace Prozharka;

use RuntimeException;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $projectRoot): self
    {
        $values = [];
        $envFile = $projectRoot . '/.env';

        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = array_map('trim', explode('=', $line, 2));
                if ($key === '') {
                    continue;
                }

                $values[$key] = trim($value, "\"'");
            }
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_scalar($value) && preg_match('/^[A-Z][A-Z0-9_]+$/', $key)) {
                $values[$key] = (string) $value;
            }
        }

        return new self($values + [
            'APP_ENV' => 'production',
            'APP_TIMEZONE' => 'Europe/Moscow',
            'SUBSCRIPTION_DAYS' => '31',
            'ACCESS_GRACE_HOURS' => '24',
            'INVITE_LINK_TTL_HOURS' => '24',
            'DRY_RUN' => '1',
            'ACCESS_REMOVAL_ENABLED' => '0',
            'PRODAMUS_SYSTEM_CODE' => 'prozharka',
            'DATABASE_PATH' => $projectRoot . '/var/app.sqlite',
        ]);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? $default;
        return $value === null ? null : (string) $value;
    }

    public function require(string $key): string
    {
        $value = trim((string) $this->get($key, ''));
        if ($value === '') {
            throw new RuntimeException("Missing required configuration: {$key}");
        }
        return $value;
    }

    public function int(string $key, int $default): int
    {
        return (int) ($this->get($key, (string) $default) ?? $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = strtolower((string) $this->get($key, $default ? '1' : '0'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}

