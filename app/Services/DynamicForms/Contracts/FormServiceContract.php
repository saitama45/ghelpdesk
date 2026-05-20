<?php

namespace App\Services\DynamicForms\Contracts;

use App\Models\FormDefinition;
use App\Models\FormRecord;
use Illuminate\Http\Request;

interface FormServiceContract
{
    /**
     * Store a new form record.
     */
    public function store(Request $request, FormDefinition $formDefinition): FormRecord;

    /**
     * Update an existing form record.
     */
    public function update(Request $request, FormDefinition $formDefinition, FormRecord $record): FormRecord;

    /**
     * Approve a form record level.
     */
    public function approve(Request $request, FormDefinition $formDefinition, FormRecord $record): void;

    /**
     * Reject a form record.
     */
    public function reject(Request $request, FormDefinition $formDefinition, FormRecord $record): void;

    /**
     * Send email reminder to current approvers.
     */
    public function notifyCurrentApprovers(FormDefinition $formDefinition, FormRecord $record): void;
}
