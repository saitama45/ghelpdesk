<?php

namespace App\Console\Commands;

use App\Services\HybridSdlcWorkbookService;
use Illuminate\Console\Command;

class GenerateHybridSdlcWorkbook extends Command
{
    protected $signature = 'activity-templates:generate-hybrid-workbook {path?}';
    protected $description = 'Generate the recommended Hybrid SDLC/Agile activity-template workbook';

    public function handle(HybridSdlcWorkbookService $workbook): int
    {
        $path = $this->argument('path') ?: base_path('References/Hybrid_SDLC_Agile_Activity_Templates.xlsx');
        $result = $workbook->write($path);
        $this->info("Generated {$result['templates']} templates and {$result['rows']} rows at {$result['path']}");
        return self::SUCCESS;
    }
}
