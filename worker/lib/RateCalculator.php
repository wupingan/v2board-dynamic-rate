<?php

declare(strict_types=1);

namespace DynamicRate\Lib;

use DateTimeImmutable;
use DateTimeZone;

final class RateCalculator
{
    /**
     * @param float $baseRate
     * @param bool $enabled
     * @param string|null $rulesJson
     * @param string $timezone
     */
    public static function resolve(float $baseRate, bool $enabled, ?string $rulesJson, string $timezone = 'Asia/Shanghai'): float
    {
        if (!$enabled) {
            return $baseRate;
        }

        $rules = json_decode((string) $rulesJson, true);
        if (!is_array($rules) || empty($rules)) {
            return $baseRate;
        }

        $tz = new DateTimeZone($timezone ?: 'Asia/Shanghai');
        $now = new DateTimeImmutable('now', $tz);
        $dow = (int) $now->format('w'); // 0(Sun)-6(Sat)
        $minute = ((int) $now->format('H')) * 60 + (int) $now->format('i');

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $days = $rule['days'] ?? null;
            $start = $rule['start'] ?? null;
            $end = $rule['end'] ?? null;
            $rate = $rule['rate'] ?? null;

            if (!is_array($days) || !is_string($start) || !is_string($end) || !is_numeric($rate)) {
                continue;
            }

            $hitDay = in_array($dow, array_map('intval', $days), true);
            if (!$hitDay) {
                continue;
            }

            $startMinute = self::toMinute($start);
            $endMinute = self::toMinute($end);
            if ($startMinute === null || $endMinute === null) {
                continue;
            }

            if (self::isInRange($minute, $startMinute, $endMinute)) {
                $r = (float) $rate;
                if ($r > 0) {
                    return $r;
                }
            }
        }

        return $baseRate;
    }

    private static function toMinute(string $time): ?int
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }
        [$h, $m] = explode(':', $time);
        return ((int) $h) * 60 + (int) $m;
    }

    private static function isInRange(int $now, int $start, int $end): bool
    {
        if ($start === $end) {
            return true;
        }
        if ($start < $end) {
            return $now >= $start && $now < $end;
        }
        // cross-day: e.g. 23:00-02:00
        return $now >= $start || $now < $end;
    }
}
