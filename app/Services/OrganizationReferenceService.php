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
    public function tree(bool $activeOnly = false, ?int $companyId = null): array
    {
        $departments = Department::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('name')
            ->get();

        if ($departments->isEmpty()) {
            return [];
        }

        // ONE query for every node, then the tree is assembled in memory. The
        // recursive version issued a query per parent (40 round trips for a dozen
        // departments), which is what made the ticket edit page crawl against the
        // remote database.
        $nodesByParent = DepartmentNode::query()
            ->whereIn('department_id', $departments->pluck('id')->all())
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (DepartmentNode $node) => $node->department_id.':'.($node->parent_id ?? ''));

        return $departments->map(fn (Department $department) => [
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
            'description' => $department->description,
            'is_active' => $department->is_active,
            'nodes' => $this->buildNodeTree($nodesByParent, $department->id, null),
        ])->values()->all();
    }

    /** Build one department's node tree from the pre-grouped rows. */
    private function buildNodeTree($nodesByParent, int $departmentId, ?int $parentId): array
    {
        return collect($nodesByParent[$departmentId.':'.($parentId ?? '')] ?? [])
            ->map(fn (DepartmentNode $node) => [
                'id' => $node->id,
                'department_id' => $node->department_id,
                'parent_id' => $node->parent_id,
                'name' => $node->name,
                'code' => $node->code,
                'description' => $node->description,
                'is_active' => $node->is_active,
                'sort_order' => $node->sort_order,
                'children' => $this->buildNodeTree($nodesByParent, $departmentId, $node->id),
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
            'department_id' => $node->department_id,
            'department_node_id' => $node->id,
            'org_path' => implode(' > ', $pathParts),
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
