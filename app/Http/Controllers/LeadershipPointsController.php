<?php

namespace App\Http\Controllers;

use App\Models\Quest;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;

class LeadershipPointsController extends Controller implements HasMiddleware
{
    private const DEFAULTS = [
        'leadership.fast_points'              => 10,
        'leadership.ontime_points'            => 5,
        'leadership.late_points'              => -5,
        'leadership.fcr_bonus'                => 5,
        'leadership.happy_customer_bonus'     => 10,
        'leadership.unhappy_customer_penalty' => -10,
        'leadership.level_beginner'           => 1000,
        'leadership.level_intermediate'       => 25000,
        'leadership.level_professional'       => 100000,
        'leadership.level_expert'             => 250000,
        'leadership.level_master'             => 500000,
        'leadership.level_guru'               => 1000000,
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:leadership_points.view', only: ['index']),
            new Middleware('can:leadership_points.edit', only: ['update', 'storeQuest', 'updateQuest', 'destroyQuest']),
        ];
    }

    public function index()
    {
        $stored = Setting::where('key', 'LIKE', 'leadership.%')->pluck('value', 'key')->toArray();
        $settings = array_merge(self::DEFAULTS, $stored);

        $quests = Quest::orderByDesc('created_at')->paginate(20)->withQueryString();

        return Inertia::render('Settings/LeadershipPoints', [
            'settings' => $settings,
            'quests'   => $quests,
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'leadership.fast_points'              => 'required|integer',
            'leadership.ontime_points'            => 'required|integer',
            'leadership.late_points'              => 'required|integer',
            'leadership.fcr_bonus'                => 'required|integer',
            'leadership.happy_customer_bonus'     => 'required|integer',
            'leadership.unhappy_customer_penalty' => 'required|integer',
            'leadership.level_beginner'           => 'required|integer|min:0',
            'leadership.level_intermediate'       => 'required|integer|min:0',
            'leadership.level_professional'       => 'required|integer|min:0',
            'leadership.level_expert'             => 'required|integer|min:0',
            'leadership.level_master'             => 'required|integer|min:0',
            'leadership.level_guru'               => 'required|integer|min:0',
        ]);

        foreach (self::DEFAULTS as $key => $default) {
            $value = $request->input($key, $default);
            Setting::set($key, $value, 'leadership');
        }

        return back()->with('success', 'Leadership points settings saved.');
    }

    public function storeQuest(Request $request)
    {
        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string',
            'criteria_type'  => 'required|in:tickets_with_awesome_rating,tickets_resolved_fast,tickets_fcr,tickets_resolved',
            'criteria_value' => 'required|integer|min:1',
            'badge_name'     => 'nullable|string|max:100',
            'bonus_points'   => 'required|integer',
            'is_active'      => 'boolean',
            'starts_at'      => 'nullable|date',
            'ends_at'        => 'nullable|date|after_or_equal:starts_at',
        ]);

        Quest::create($data);

        return back()->with('success', 'Quest created.');
    }

    public function updateQuest(Request $request, Quest $quest)
    {
        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string',
            'criteria_type'  => 'required|in:tickets_with_awesome_rating,tickets_resolved_fast,tickets_fcr,tickets_resolved',
            'criteria_value' => 'required|integer|min:1',
            'badge_name'     => 'nullable|string|max:100',
            'bonus_points'   => 'required|integer',
            'is_active'      => 'boolean',
            'starts_at'      => 'nullable|date',
            'ends_at'        => 'nullable|date|after_or_equal:starts_at',
        ]);

        $quest->update($data);

        return back()->with('success', 'Quest updated.');
    }

    public function destroyQuest(Quest $quest)
    {
        $quest->delete();

        return back()->with('success', 'Quest deleted.');
    }
}
