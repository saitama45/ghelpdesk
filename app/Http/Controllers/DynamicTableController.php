<?php

namespace App\Http\Controllers;

use App\Models\TableDefinition;
use App\Models\TableRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DynamicTableController extends Controller
{
    public function index(Request $request, $slug)
    {
        $table = TableDefinition::where('slug', $slug)->firstOrFail();
        
        $query = TableRecord::where('table_definition_id', $table->id);
        
        // Basic search in the JSON data column
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('data', 'like', "%{$search}%");
        }
        
        $records = $query->with(['creator', 'updator'])
                        ->latest()
                        ->paginate($request->get('per_page', 10))
                        ->withQueryString();
        
        return Inertia::render('DynamicTable/Index', [
            'table' => $table,
            'records' => $records,
        ]);
    }

    public function store(Request $request, $slug)
    {
        $table = TableDefinition::where('slug', $slug)->firstOrFail();
        
        // In a real app, you'd use the $table->form_schema to build dynamic validation rules
        // For now, we'll just store the data
        
        TableRecord::create([
            'table_definition_id' => $table->id,
            'data' => $request->all(),
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Record created successfully');
    }

    public function update(Request $request, $slug, $id)
    {
        $table = TableDefinition::where('slug', $slug)->firstOrFail();
        $record = TableRecord::where('table_definition_id', $table->id)->findOrFail($id);
        
        $record->update([
            'data' => $request->all(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Record updated successfully');
    }

    public function destroy($slug, $id)
    {
        $table = TableDefinition::where('slug', $slug)->firstOrFail();
        $record = TableRecord::where('table_definition_id', $table->id)->findOrFail($id);
        
        $record->delete();

        return redirect()->back()->with('success', 'Record deleted successfully');
    }
}
