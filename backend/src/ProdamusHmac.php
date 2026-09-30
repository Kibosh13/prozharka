<?php

declare(strict_types=1);

namespace Prozharka;

final class ProdamusHmac
{
    public static function sign(array $data, string $secret): string
    {
        $normalized = self::normalize($data);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode Prodamus payload');
        }

        return hash_hmac('sha256', $json, $secret);
    }

    public static function verify(array $data, string $secret, string $signature): bool
    {
        return $signature !== '' && hash_equals(self::sign($data, $secret), trim($signature));
    }

    private static function normalize(array $data): array
    {
        ksort($data, SORT_STRING);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalize($value);
            } elseif ($value === null) {
                $data[$key] = '';
            } elseif (is_bool($value)) {
                $data[$key] = $value ? '1' : '0';
            } else {
                $data[$key] = (string) $value;
            }
        }
        return $data;
    }
}

