<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Enums;

use Illuminate\Support\Carbon;

enum Period: string
{
    case HOUR = 'hour';
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
    case YEAR = 'year';

    /**
     * Get the display label for the period.
     */
    public function label(): string
    {
        return match ($this) {
            self::HOUR => 'Hourly',
            self::DAY => 'Daily',
            self::WEEK => 'Weekly',
            self::MONTH => 'Monthly',
            self::YEAR => 'Yearly',
        };
    }

    /**
     * Get the next reset date from a given date.
     */
    public function getNextResetDate(?\DateTimeInterface $from = null): Carbon
    {
        $from = $from ? Carbon::instance($from) : now();

        return match ($this) {
            self::HOUR => $from->copy()->addHour()->startOfHour(),
            self::DAY => $from->copy()->addDay()->startOfDay(),
            self::WEEK => $from->copy()->addWeek()->startOfWeek(),
            self::MONTH => $from->copy()->addMonth()->startOfMonth(),
            self::YEAR => $from->copy()->addYear()->startOfYear(),
        };
    }

    /**
     * Get the start of the period for a given timestamp.
     */
    public function getPeriodStart(?\DateTimeInterface $timestamp = null): Carbon
    {
        $timestamp = $timestamp ? Carbon::instance($timestamp) : now();

        return match ($this) {
            self::HOUR => $timestamp->copy()->startOfHour(),
            self::DAY => $timestamp->copy()->startOfDay(),
            self::WEEK => $timestamp->copy()->startOfWeek(),
            self::MONTH => $timestamp->copy()->startOfMonth(),
            self::YEAR => $timestamp->copy()->startOfYear(),
        };
    }

    /**
     * Get the end of the period for a given timestamp.
     */
    public function getPeriodEnd(?\DateTimeInterface $timestamp = null): Carbon
    {
        $timestamp = $timestamp ? Carbon::instance($timestamp) : now();

        return match ($this) {
            self::HOUR => $timestamp->copy()->endOfHour(),
            self::DAY => $timestamp->copy()->endOfDay(),
            self::WEEK => $timestamp->copy()->endOfWeek(),
            self::MONTH => $timestamp->copy()->endOfMonth(),
            self::YEAR => $timestamp->copy()->endOfYear(),
        };
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
