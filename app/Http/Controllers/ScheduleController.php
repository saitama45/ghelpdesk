<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\User;
use App\Models\Store;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ScheduleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:schedules.view', only: ['index']),
            new Middleware('can:schedules.create', only: ['store']),
            new Middleware('can:schedules.edit', only: ['update']),
        ];
    }

    public function index(Request $request)
    {
        $query = Schedule::with(['user', 'store', 'ticket']);

        if ($request->filled('start') && $request->filled('end')) {
            $query->whereBetween('start_time', [$request->start, $request->end]);
        }

        if ($request->filled('user_id')) {
            if ($request->user_id === 'my') {
                $query->where('user_id', auth()->id());
            } else {
                $query->where('user_id', $request->user_id);
            }
        }

        $schedules = $query->get()->map(function($schedule) {
            return [
                'id' => $schedule->id,
                'user_id' => $schedule->user_id,
                'store_id' => $schedule->store_id,
                'ticket_id' => $schedule->ticket_id,
                'status' => $schedule->status,
                'start_time' => $schedule->start_time->toIso8601String(),
                'end_time' => $schedule->end_time->toIso8601String(),
                'pickup_start' => $schedule->pickup_start ? substr($schedule->pickup_start, 0, 5) : null,
                'pickup_end' => $schedule->pickup_end ? substr($schedule->pickup_end, 0, 5) : null,
                'backlogs_start' => $schedule->backlogs_start ? substr($schedule->backlogs_start, 0, 5) : null,
                'backlogs_end' => $schedule->backlogs_end ? substr($schedule->backlogs_end, 0, 5) : null,
                'remarks' => $schedule->remarks,
                'user' => $schedule->user,
                'store' => $schedule->store,
                'ticket' => $schedule->ticket,
            ];
        });
        
        $users = User::active()->orderBy('name')->get();
        $stores = Store::where('is_active', true)->orderBy('name')->get();

        return Inertia::render('Schedules/Index', [
            'schedules' => $schedules,
            'users' => $users,
            'stores' => $stores,
            'filters' => $request->only(['user_id']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'store_id' => 'nullable|exists:stores,id',
            'status' => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time',
            'pickup_start' => 'nullable|string',
            'pickup_end' => 'nullable|string',
            'backlogs_start' => 'nullable|string',
            'backlogs_end' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $startTime = \Illuminate\Support\Carbon::parse($request->start_time);
        $endTime = \Illuminate\Support\Carbon::parse($request->end_time);

        // Check for overlaps
        $overlap = Schedule::where('user_id', $request->user_id)
            ->where(function($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                      ->orWhereBetween('end_time', [$startTime, $endTime])
                      ->orWhere(function($q) use ($startTime, $endTime) {
                          $q->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                      });
            })->exists();

        if ($overlap) {
            return redirect()->back()->withErrors(['start_time' => 'This user already has a schedule that overlaps with the selected time range.']);
        }

        Schedule::create($validated);

        return redirect()->back()->with('success', 'Schedule created successfully');
    }

    public function update(Request $request, Schedule $schedule)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'store_id' => 'nullable|exists:stores,id',
            'status' => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time',
            'pickup_start' => 'nullable|string',
            'pickup_end' => 'nullable|string',
            'backlogs_start' => 'nullable|string',
            'backlogs_end' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $startTime = \Illuminate\Support\Carbon::parse($request->start_time);
        $endTime = \Illuminate\Support\Carbon::parse($request->end_time);

        // Check for overlaps
        $overlap = Schedule::where('user_id', $request->user_id)
            ->where('id', '!=', $schedule->id)
            ->where(function($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                      ->orWhereBetween('end_time', [$startTime, $endTime])
                      ->orWhere(function($q) use ($startTime, $endTime) {
                          $q->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                      });
            })->exists();

        if ($overlap) {
            return redirect()->back()->withErrors(['start_time' => 'This user already has a schedule that overlaps with the selected time range.']);
        }

        $schedule->update($validated);

        return redirect()->back()->with('success', 'Schedule updated successfully');
    }
}
