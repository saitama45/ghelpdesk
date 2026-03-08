<?php

namespace App\Http\Controllers;

use App\Models\CannedMessage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CannedMessageController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:canned_messages.view', only: ['index']),
            new Middleware('can:canned_messages.create', only: ['store']),
            new Middleware('can:canned_messages.edit', only: ['update']),
            new Middleware('can:canned_messages.delete', only: ['destroy']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = CannedMessage::query();

        if ($request->filled('search')) {
            $query->where('title', 'like', "%{$request->search}%")
                  ->orWhere('content', 'like', "%{$request->search}%");
        }

        $cannedMessages = $query->latest()
            ->paginate($request->get('per_page', 10))
            ->withQueryString();

        return Inertia::render('CannedMessages/Index', [
            'cannedMessages' => $cannedMessages,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'is_active' => 'boolean',
        ]);

        CannedMessage::create($validated);

        return redirect()->back()->with('success', 'Canned message created successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CannedMessage $cannedMessage)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'is_active' => 'boolean',
        ]);

        $cannedMessage->update($validated);

        return redirect()->back()->with('success', 'Canned message updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CannedMessage $cannedMessage)
    {
        $cannedMessage->delete();
        return redirect()->back()->with('success', 'Canned message deleted successfully');
    }
}
