<?php

namespace App\Services\DynamicForms;

use App\Services\DynamicForms\Contracts\FormServiceContract;
use Illuminate\Support\Facades\App;

class FormServiceFactory
{
    /**
     * Map of form slugs to their dedicated service classes.
     * 
     * @var array
     */
    protected array $serviceMap = [
        // 'incident-report' => \App\Services\DynamicForms\IncidentReportService::class,
    ];

    /**
     * Resolve the service for a given form slug.
     *
     * @param string $slug
     * @return FormServiceContract
     */
    public function make(string $slug): FormServiceContract
    {
        $serviceClass = $this->serviceMap[$slug] ?? DefaultFormService::class;

        return App::make($serviceClass);
    }
}
