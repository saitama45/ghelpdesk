<?php

namespace App\Console\Commands;

use App\Services\HybridSdlcWorkbookService;
use Illuminate\Console\Command;

class GenerateHybridSdlcWorkbook extends Command
{
    protected $signature = 'activity-templates:generate-hybrid-workbook
        {path? : Combined workbook output path}
        {--separate : Also generate one workbook per template}';
    protected $description = 'Generate the recommended Hybrid SDLC/Agile activity-template workbook';

    public function handle(HybridSdlcWorkbookService $workbook): int
    {
        $path = $this->argument('path') ?: base_path('References/Hybrid_SDLC_Agile_Activity_Templates.xlsx');
        $result = $workbook->write($path);
        $this->info("Generated {$result['templates']} templates and {$result['rows']} rows at {$result['path']}");

        if ($this->option('separate')) {
            $directory = base_path('References/Hybrid SDLC Agile Templates');
            $results = $workbook->writeSeparate($directory);
            $this->info('Generated '.count($results)." standalone workbooks in {$directory}");
            foreach ($results as $standalone) {
                $this->line(" - ".basename($standalone['path'])." ({$standalone['rows']} rows)");
            }
        }

        return self::SUCCESS;
    }
}
