<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Enums;

use Illuminate\Support\Carbon;

enum Period: string
{
    case HOURLY = 'hourly';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';

    /**
     * Get the display label for the period.
     */
    public function label(): string
    {
        return match ($this) {
            self::HOURLY => 'Hourly',
            self::DAILY => 'Daily',
            self::WEEKLY => 'Weekly',
            self::MONTHLY => 'Monthly',
            self::YEARLY => 'Yearly',
        };
    }

    /**
     * Get the next reset date from a given date.
     */
    public function getNextResetDate(?\DateTimeInterface $from = null): Carbon
    {
        $from = $from ? Carbon::instance($from) : now();

        return match ($this) {
            self::HOURLY => $from->copy()->addHour()->startOfHour(),
            self::DAILY => $from->copy()->addDay()->startOfDay(),
            self::WEEKLY => $from->copy()->addWeek()->startOfWeek(),
            self::MONTHLY => $from->copy()->addMonth()->startOfMonth(),
            self::YEARLY => $from->copy()->addYear()->startOfYear(),
        };
    }

    /**
     * Get the start of the period for a given timestamp.
     */
    public function getPeriodStart(?\DateTimeInterface $timestamp = null): Carbon
    {
        $timestamp = $timestamp ? Carbon::instance($timestamp) : now();

        return match ($this) {
            self::HOURLY => $timestamp->copy()->startOfHour(),
            self::DAILY => $timestamp->copy()->startOfDay(),
            self::WEEKLY => $timestamp->copy()->startOfWeek(),
            self::MONTHLY => $timestamp->copy()->startOfMonth(),
            self::YEARLY => $timestamp->copy()->startOfYear(),
        };
    }

    /**
     * Get the end of the period for a given timestamp.
     */
    public function getPeriodEnd(?\DateTimeInterface $timestamp = null): Carbon
    {
        $timestamp = $timestamp ? Carbon::instance($timestamp) : now();

        return match ($this) {
            self::HOURLY => $timestamp->copy()->endOfHour(),
            self::DAILY => $timestamp->copy()->endOfDay(),
            self::WEEKLY => $timestamp->copy()->endOfWeek(),
            self::MONTHLY => $timestamp->copy()->endOfMonth(),
            self::YEARLY => $timestamp->copy()->endOfYear(),
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
