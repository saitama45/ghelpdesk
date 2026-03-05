<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Category;

class SlaService
{
    /**
     * Calculate the target datetime based on business hours.
     */
    public static function calculateTarget(Carbon $startDate, int $hours, Category $category)
    {
        $targetDate = $startDate->copy();
        $startTime = $category->business_start_time; // e.g., "08:00:00"
        $endTime = $category->business_end_time;     // e.g., "17:00:00"
        $workingDays = $category->working_days;      // e.g., [1, 2, 3, 4, 5]

        // Parse work hours robustly
        $workStart = Carbon::parse($startTime);
        $workEnd = Carbon::parse($endTime);
        $dailyWorkSeconds = $workStart->diffInSeconds($workEnd);
        
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
    public static function addSecondsRespectingBusinessHours(Carbon $startDate, int $secondsToAdd, Category $category)
    {
        // For simplicity, we can treat "pause duration" as a direct push of the target, 
        // OR we can re-calculate the target using the original duration.
        // Usually, ITIL logic says if you were paused for 1 hour, your deadline is pushed by 1 hour.
        
        // But if that push lands on a weekend, it should move to Monday.
        
        $newTarget = $startDate->copy()->addSeconds($secondsToAdd);
        $startTime = $category->business_start_time;
        $endTime = $category->business_end_time;
        $workingDays = $category->working_days;

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
