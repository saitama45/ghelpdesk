<?php

namespace Database\Seeders;

use App\Models\ReferenceOption;
use Illuminate\Database\Seeder;

class StoreReferenceOptionSeeder extends Seeder
{
    public function run(): void
    {
        $sets = [
            'store_hookup' => ['Ayala', 'Robinsons', 'Megaworld', 'SM'],
            'store_system' => ['Alliance-OLD', 'Alliance ALTO', 'Storehub'],
            'store_telco' => ['Globe', 'PLDT'],
            'store_connectivity_type' => ['Fiber', 'Copper/DSL', 'Prepaid'],
            'store_remote_app' => ['Teamviewer', 'Anydesk', 'Ultraviewer'],
        ];

        foreach ($sets as $type => $values) {
            $sortOrder = 1;
            foreach ($values as $value) {
                ReferenceOption::firstOrCreate(
                    ['type' => $type, 'value' => $value],
                    ['label' => $value, 'sort_order' => $sortOrder]
                );
                $sortOrder++;
            }
        }
    }
}
