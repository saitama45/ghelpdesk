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
    private static function getSetting($key, $subUnit = null, $default = null)
    {
        if ($subUnit) {
            $slug = \Illuminate\Support\Str::slug($subUnit, '_');
            $overrideKey = "{$key}_{$slug}";
            $value = Setting::get($overrideKey);
            if ($value !== null) return $value;
        }
        return Setting::get($key, $default);
    }

    /**
     * Calculate the target datetime based on business hours.
     */
    public static function calculateTarget(Carbon $startDate, $itemId, string $type = 'response', $subUnit = null)
    {
        $item = \App\Models\Item::find($itemId);
        $priority = $item ? strtolower($item->priority) : 'medium';

        // Get target hours from settings based on priority and type
        $hours = (int) Setting::get("sla_{$priority}_{$type}", $type === 'response' ? 24 : 72);

        $targetDate = $startDate->copy();
        
        $startTime = self::getSetting('business_start_time', $subUnit, '08:00:00');
        $endTime = self::getSetting('business_end_time', $subUnit, '17:00:00');
        $workingDaysRaw = self::getSetting('working_days', $subUnit);
        $workingDays = $workingDaysRaw ? json_decode($workingDaysRaw, true) : [1, 2, 3, 4, 5];

        // Parse work hours robustly
        $workStart = Carbon::parse($startTime);
        $workEnd = Carbon::parse($endTime);
        
        $secondsToAdd = $hours * 3600;

        while ($secondsToAdd > 0) {
            // 1. If today is not a working day, skip to the next working day start
            if (!in_array($targetDate->dayOfWeekIso, $workingDays)) {
                $targetDate->addDay()->setTime($workStart->hour, $workStart->minute, 0);
                continue;
            }

            // 2. If target is before work start, move to work start
            $todayStart = $targetDate->copy()->setTime($workStart->hour, $workStart->minute, 0);
            if ($targetDate->lt($todayStart)) {
                $targetDate = $todayStart;
            }

            // 3. If target is after work end, move to tomorrow start
            $todayEnd = $targetDate->copy()->setTime($workEnd->hour, $workEnd->minute, 0);
            if ($targetDate->gte($todayEnd)) {
                $targetDate->addDay()->setTime($workStart->hour, $workStart->minute, 0);
                continue;
            }

            // 4. Calculate available seconds today
            $availableSecondsToday = $targetDate->diffInSeconds($todayEnd);

            if ($secondsToAdd <= $availableSecondsToday) {
                // Done! Target is within today's work hours
                $targetDate->addSeconds($secondsToAdd);
                $secondsToAdd = 0;
            } else {
                // Move to next day start
                $secondsToAdd -= $availableSecondsToday;
                $targetDate->addDay()->setTime($workStart->hour, $workStart->minute, 0);
            }
        }

        return $targetDate;
    }

    /**
     * Simply add seconds while respecting business hours (for resuming from pause).
     */
    public static function addSecondsRespectingBusinessHours(Carbon $startDate, int $secondsToAdd, Category $category = null, $subUnit = null)
    {
        $newTarget = $startDate->copy()->addSeconds($secondsToAdd);
        
        $startTime = self::getSetting('business_start_time', $subUnit, '08:00:00');
        $endTime = self::getSetting('business_end_time', $subUnit, '17:00:00');
        $workingDaysRaw = self::getSetting('working_days', $subUnit);
        $workingDays = $workingDaysRaw ? json_decode($workingDaysRaw, true) : [1, 2, 3, 4, 5];

        $workStart = Carbon::parse($startTime);
        $workEnd = Carbon::parse($endTime);

        while (true) {
            // If it lands on a non-working day
            if (!in_array($newTarget->dayOfWeekIso, $workingDays)) {
                $newTarget->addDay()->setTime($workStart->hour, $workStart->minute, $newTarget->second);
                continue;
            }

            // If it lands after work end
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
