<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\User;

class OrganizationReferenceService
{
    /**
     * Fetch the complete organizational tree.
     */
    public function tree(bool $activeOnly = false): array
    {
        $departments = Department::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        return $departments->map(fn (Department $department) => [
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
            'description' => $department->description,
            'is_active' => $department->is_active,
            'nodes' => $this->buildNodeTree($department->id, null, $activeOnly),
        ])->values()->all();
    }

    /**
     * Recursively build the node tree for a department.
     */
    private function buildNodeTree(int $departmentId, ?int $parentId, bool $activeOnly): array
    {
        return DepartmentNode::query()
            ->where('department_id', $departmentId)
            ->where('parent_id', $parentId)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (DepartmentNode $node) => [
                'id' => $node->id,
                'department_id' => $node->department_id,
                'parent_id' => $node->parent_id,
                'name' => $node->name,
                'code' => $node->code,
                'description' => $node->description,
                'is_active' => $node->is_active,
                'sort_order' => $node->sort_order,
                'children' => $this->buildNodeTree($departmentId, $node->id, $activeOnly),
            ])->values()->all();
    }

    /**
     * Generate user placement payload based on a leaf node ID.
     */
    public function payloadFromNodeId(?int $nodeId, bool $activeOnly = true): array
    {
        if (!$nodeId) {
            return $this->clearPayload();
        }

        $node = DepartmentNode::with('department')->find($nodeId);

        if (!$node || ($activeOnly && (!$node->is_active || !$node->department->is_active))) {
            return $this->clearPayload();
        }

        // Build the breadcrumb path e.g. "Section > Unit > SubUnit"
        $pathParts = [];
        $current = $node;
        while ($current) {
            array_unshift($pathParts, $current->name);
            $current = $current->parent_id ? DepartmentNode::find($current->parent_id) : null;
        }

        return [
            'department' => $node->department->name,
            'department_code' => $node->department->code,
            'department_id' => $node->department_id,
            'department_node_id' => $node->id,
            'org_path' => implode(' > ', $pathParts),
            'node_name' => $node->name,
            'node_code' => $node->code,
        ];
    }

    /**
     * Clear organizational placement fields.
     */
    public function clearPayload(): array
    {
        return [
            'department' => null,
            'department_id' => null,
            'department_node_id' => null,
            'org_path' => null,
        ];
    }

    /**
     * Apply a specific node placement to a user.
     */
    public function applyNodeToUser(User $user, int $nodeId): void
    {
        $user->forceFill($this->payloadFromNodeId($nodeId))->save();
    }

    /**
     * Remove all organizational placement from a user.
     */
    public function clearUserOrganization(User $user): void
    {
        $user->forceFill($this->clearPayload())->save();
    }
}
