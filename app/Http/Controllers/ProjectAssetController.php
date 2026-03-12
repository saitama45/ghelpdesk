<?php

namespace App\Http\Controllers;

use App\Models\ProjectAsset;
use Illuminate\Http\Request;

class ProjectAssetController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'project_task_id' => 'nullable',
            'category' => 'required|string',
            'item_name' => 'required|string|max:255',
            'model_specs' => 'nullable|string',
            'quantity' => 'required|integer|min:1',
            'delivery_status' => 'nullable|string',
            'responsible' => 'nullable|string',
            'store_delivery_date' => 'nullable|date',
            'store_setup_date' => 'nullable|date',
            'remarks' => 'nullable|string',
        ]);

        if (array_key_exists('project_task_id', $validated)) {
            $validated['project_task_id'] = $validated['project_task_id'] ?: null;
        }

        ProjectAsset::create($validated);

        return redirect()->back()->with('success', 'Asset added successfully.');
    }

    public function update(Request $request, ProjectAsset $projects_asset)
    {
        $validated = $request->validate([
            'category' => 'sometimes|required|string',
            'item_name' => 'sometimes|required|string|max:255',
            'model_specs' => 'nullable|string',
            'quantity' => 'sometimes|required|integer|min:1',
            'delivery_status' => 'nullable|string',
            'responsible' => 'nullable|string',
            'store_delivery_date' => 'nullable|date',
            'store_setup_date' => 'nullable|date',
            'remarks' => 'nullable|string',
        ]);

        $projects_asset->update($validated);

        return redirect()->back()->with('success', 'Asset updated successfully.');
    }

    public function destroy(ProjectAsset $projects_asset)
    {
        $projects_asset->delete();

        return redirect()->back()->with('success', 'Asset removed successfully.');
    }
}
