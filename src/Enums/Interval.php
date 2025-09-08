<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Enums;

enum Interval: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';
    case LIFETIME = 'lifetime';

    /**
     * Get the display label for the interval.
     */
    public function label(): string
    {
        return match ($this) {
            self::DAILY => 'per day',
            self::WEEKLY => 'per week',
            self::MONTHLY => 'per month',
            self::YEARLY => 'per year',
            self::LIFETIME => 'one-time',
        };
    }

    /**
     * Check if this is a recurring interval.
     */
    public function isRecurring(): bool
    {
        return $this !== self::LIFETIME;
    }

    /**
     * Get all recurring intervals.
     *
     * @return array<self>
     */
    public static function recurring(): array
    {
        return array_filter(
            self::cases(),
            fn (self $interval) => $interval->isRecurring()
        );
    }

    /**
     * Get all values as an array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
