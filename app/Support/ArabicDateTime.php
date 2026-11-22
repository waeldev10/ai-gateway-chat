<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class ArabicDateTime
{
    /**
     * Relative timestamp for conversation list items.
     *
     * Recent activity is relative, yesterday and the previous week use short
     * Arabic phrases, and older entries fall back to an Arabic date.
     */
    public static function forConversation(CarbonInterface $at, ?CarbonInterface $now = null): string
    {
        $now = $now ? Carbon::instance($now) : Carbon::now();
        $date = Carbon::instance($at)->locale('ar');

        if ($date->greaterThan($now)) {
            return 'الآن';
        }

        $seconds = (int) abs($now->diffInSeconds($date));

        if ($seconds < 60) {
            return 'الآن';
        }

        if ($seconds < 3600) {
            return 'منذ '.self::pluralize((int) ($seconds / 60), 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة');
        }

        if ($date->isSameDay($now)) {
            return 'منذ '.self::pluralize((int) ($seconds / 3600), 'ساعة', 'ساعتين', 'ساعات', 'ساعة');
        }

        if ($date->toDateString() === $now->copy()->subDay()->toDateString()) {
            return 'أمس';
        }

        $days = (int) $date->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());

        if ($days >= 2 && $days < 7) {
            return 'منذ '.self::pluralize($days, 'يوم', 'يومين', 'أيام', 'يوماً');
        }

        if ((int) $date->format('Y') === (int) $now->format('Y')) {
            return self::digits($date->translatedFormat('j F'));
        }

        return self::digits($date->translatedFormat('j F Y'));
    }

    /**
     * User-friendly timestamp for a single chat message.
     */
    public static function forMessage(CarbonInterface $at, ?CarbonInterface $now = null): string
    {
        $now = $now ? Carbon::instance($now) : Carbon::now();
        $date = Carbon::instance($at)->locale('ar');

        if ($date->greaterThan($now)) {
            return 'الآن';
        }

        $seconds = (int) abs($now->diffInSeconds($date));

        if ($seconds < 60) {
            return 'الآن';
        }

        if ($seconds < 3600) {
            return 'منذ '.self::pluralize((int) ($seconds / 60), 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة');
        }

        if ($date->isSameDay($now)) {
            return 'منذ '.self::pluralize((int) ($seconds / 3600), 'ساعة', 'ساعتين', 'ساعات', 'ساعة');
        }

        if ($date->toDateString() === $now->copy()->subDay()->toDateString()) {
            return 'أمس، '.self::time($date);
        }

        if ((int) $date->format('Y') === (int) $now->format('Y')) {
            return self::digits($date->translatedFormat('j F')).'، '.self::time($date);
        }

        return self::digits($date->translatedFormat('j F Y')).'، '.self::time($date);
    }

    /**
     * Full date and time for a search result.
     */
    public static function forSearch(CarbonInterface $at): string
    {
        $date = Carbon::instance($at)->locale('ar');

        return self::digits($date->translatedFormat('j F Y')).'، '.self::time($date);
    }

    /**
     * Time only, e.g. ٤:٣٠ م.
     */
    public static function timeOnly(CarbonInterface $at): string
    {
        return self::time(Carbon::instance($at)->locale('ar'));
    }

    private static function pluralize(int $n, string $one, string $two, string $few, string $many): string
    {
        if ($n === 1) {
            return $one;
        }

        if ($n === 2) {
            return $two;
        }

        if ($n >= 3 && $n <= 10) {
            return self::digits($n).' '.$few;
        }

        return self::digits($n).' '.$many;
    }

    private static function time(Carbon $date): string
    {
        return self::digits($date->translatedFormat('g:i a'));
    }

    public static function digits(string|int $value): string
    {
        return strtr((string) $value, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }
}
