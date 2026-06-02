<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Category;
use App\Models\Setting;

class SlaService
{
    /**
     * Get a setting with optional sub-unit override.
     */
    private static function getSetting(
        $key,
        $subUnit = null,
        $default = null,
        ?int $departmentId = null,
        ?int $departmentNodeId = null
    )
    {
        return Setting::getScoped(
            $key,
            $default,
            $departmentId,
            $departmentNodeId,
            $subUnit
        );
    }

    /**
     * Parse and validate business hours settings. Returns safe Carbon instances.
     */
    private static function parseWorkHours($subUnit, ?int $departmentId = null, ?int $departmentNodeId = null): array
    {
        $startTime      = self::getSetting('business_start_time', $subUnit, '08:00:00', $departmentId, $departmentNodeId);
        $endTime        = self::getSetting('business_end_time',   $subUnit, '17:00:00', $departmentId, $departmentNodeId);
        $workingDaysRaw = self::getSetting('working_days',        $subUnit, null, $departmentId, $departmentNodeId);

        $workingDays = [];
        if ($workingDaysRaw) {
            $decoded = json_decode($workingDaysRaw, true);
            if (is_array($decoded) && !empty($decoded)) {
                $workingDays = array_map('intval', $decoded);
            }
        }
        if (empty($workingDays)) {
            $workingDays = [1, 2, 3, 4, 5]; // Mon–Fri
        }

        $workStart = Carbon::parse($startTime);
        $workEnd   = Carbon::parse($endTime);

        // Guard against misconfigured hours (start >= end)
        if ($workStart->gte($workEnd)) {
            $workStart = Carbon::parse('08:00:00');
            $workEnd   = Carbon::parse('17:00:00');
        }

        return [$workStart, $workEnd, $workingDays];
    }

    /**
     * Advance $date to the start of the next working day (or same day if already valid).
     * The $guard parameter prevents infinite loops when working days are misconfigured.
     */
    private static function nextWorkingDayStart(Carbon $date, array $workingDays, Carbon $workStart): Carbon
    {
        $guard = 0;
        while (!in_array($date->dayOfWeekIso, $workingDays) && $guard++ < 14) {
            $date->addDay();
        }
        return $date->setTime($workStart->hour, $workStart->minute, 0);
    }

    /**
     * Calculate the target datetime based on business hours.
     * Uses a mathematical day-skip approach — O(working_days) not O(hours).
     */
    public static function calculateTarget(
        Carbon $startDate,
        $itemId,
        string $type = 'response',
        $subUnit = null,
        ?int $departmentId = null,
        ?int $departmentNodeId = null
    )
    {
        $item     = \App\Models\Item::find($itemId);
        $priority = $item ? strtolower($item->priority) : 'medium';
        $default  = $type === 'response' ? 24 : 72;
        $hours    = (int) Setting::get("sla_{$priority}_{$type}", $default);
        if ($hours <= 0) {
            $hours = $default;
        }

        [$workStart, $workEnd, $workingDays] = self::parseWorkHours($subUnit, $departmentId, $departmentNodeId);

        $secondsPerWorkDay = (int) $workStart->diffInSeconds($workEnd); // e.g. 32400 for 9-hour day
        $secondsToAdd      = $hours * 3600;
        $targetDate        = $startDate->copy();

        // ── Step 1: land on a valid working day ──────────────────────────────
        if (!in_array($targetDate->dayOfWeekIso, $workingDays)) {
            $targetDate = self::nextWorkingDayStart($targetDate, $workingDays, $workStart);
        }

        $todayStart = $targetDate->copy()->setTime($workStart->hour, $workStart->minute, 0);
        $todayEnd   = $targetDate->copy()->setTime($workEnd->hour,   $workEnd->minute,   0);

        if ($targetDate->lt($todayStart)) {
            $targetDate = $todayStart->copy();
        } elseif ($targetDate->gte($todayEnd)) {
            // Already past end-of-day — move to next working day
            $targetDate = self::nextWorkingDayStart(
                $targetDate->addDay()->startOfDay(),
                $workingDays,
                $workStart
            );
        }

        // ── Step 2: consume today's partial window ───────────────────────────
        $todayEnd       = $targetDate->copy()->setTime($workEnd->hour, $workEnd->minute, 0);
        $availableToday = max(0, $targetDate->diffInSeconds($todayEnd));

        if ($secondsToAdd <= $availableToday) {
            return $targetDate->addSeconds($secondsToAdd);
        }

        $secondsToAdd -= $availableToday;

        // ── Step 3: today's window is exhausted — move to the next working day ──
        // Must advance the date BEFORE calculating remaining days, because today's
        // partial window was already consumed in Step 2 and cannot be reused.
        $targetDate->addDay();
        $guard = 0;
        while (!in_array($targetDate->dayOfWeekIso, $workingDays) && $guard++ < 14) {
            $targetDate->addDay();
        }

        if ($secondsPerWorkDay > 0) {
            $fullWorkDays     = intdiv($secondsToAdd, $secondsPerWorkDay);
            $remainingSeconds = $secondsToAdd % $secondsPerWorkDay;
        } else {
            $fullWorkDays     = 0;
            $remainingSeconds = 0;
        }

        // Advance any additional full working days beyond the first
        for ($i = 0; $i < $fullWorkDays; $i++) {
            $targetDate->addDay();
            $guard = 0;
            while (!in_array($targetDate->dayOfWeekIso, $workingDays) && $guard++ < 14) {
                $targetDate->addDay();
            }
        }
        $targetDate->setTime($workStart->hour, $workStart->minute, 0);

        return $targetDate->addSeconds($remainingSeconds);
    }

    /**
     * Simply add seconds while respecting business hours (for resuming from pause).
     */
    public static function addSecondsRespectingBusinessHours(
        Carbon $startDate,
        int $secondsToAdd,
        Category $category = null,
        $subUnit = null,
        ?int $departmentId = null,
        ?int $departmentNodeId = null
    )
    {
        $newTarget = $startDate->copy()->addSeconds($secondsToAdd);

        [$workStart, $workEnd, $workingDays] = self::parseWorkHours($subUnit, $departmentId, $departmentNodeId);

        // Adjust the landing time to be within business hours.
        // Hard-capped at 365 iterations to prevent infinite loops from bad data.
        $guard = 0;
        while ($guard++ < 365) {
            if (!in_array($newTarget->dayOfWeekIso, $workingDays)) {
                $newTarget->addDay()->setTime($workStart->hour, $workStart->minute, $newTarget->second);
                continue;
            }

            $todayEnd = $newTarget->copy()->setTime($workEnd->hour, $workEnd->minute, 0);
            if ($newTarget->gt($todayEnd)) {
                $secondsOver = $todayEnd->diffInSeconds($newTarget);
                $newTarget->addDay()->setTime($workStart->hour, $workStart->minute, 0)->addSeconds($secondsOver);
                continue;
            }

            break;
        }

        return $newTarget;
    }
}
