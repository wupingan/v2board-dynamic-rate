<?php

declare(strict_types=1);

namespace DynamicRate\Lib;

final class EnvLoader
{
    /**
     * @return array<string, string>
     */
    public static function fromFile(string $envPath): array
    {
        $result = [];
        if (!is_file($envPath)) {
            return $result;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, "\"'");
            $result[$key] = $value;
        }

        return $result;
    }
}
