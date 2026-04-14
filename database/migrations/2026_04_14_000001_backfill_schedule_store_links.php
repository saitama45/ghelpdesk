<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = Carbon::now('Asia/Manila');

        $legacySchedules = DB::table('schedules as s')
            ->leftJoin('schedule_stores as ss', 'ss.schedule_id', '=', 's.id')
            ->whereNull('ss.id')
            ->whereIn('s.status', ['On-site', 'Off-site'])
            ->whereNotNull('s.store_id')
            ->select('s.id', 's.store_id', 's.start_time', 's.end_time', 's.remarks')
            ->get();

        foreach ($legacySchedules as $schedule) {
            DB::table('schedule_stores')->insert([
                'schedule_id' => $schedule->id,
                'store_id' => $schedule->store_id,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'grace_period_minutes' => 30,
                'remarks' => $schedule->remarks,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $singleStoreSchedules = DB::table('schedule_stores')
            ->select('schedule_id', DB::raw('MIN(id) as schedule_store_id'))
            ->groupBy('schedule_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('schedule_store_id', 'schedule_id');

        foreach ($singleStoreSchedules as $scheduleId => $scheduleStoreId) {
            DB::table('attendance_logs')
                ->where('schedule_id', $scheduleId)
                ->whereNull('schedule_store_id')
                ->update([
                    'schedule_store_id' => $scheduleStoreId,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data repair migration intentionally left irreversible.
    }
};