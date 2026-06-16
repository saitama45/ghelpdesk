<?php

use App\Models\Category;
use App\Models\Item;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $category = Category::firstOrCreate(
            ['name' => 'CCTV'],
            ['description' => 'CCTV system monitoring and maintenance', 'is_active' => true]
        );

        $items = [
            'CCTV – General',
            'CCTV – DVR/NVR',
            'CCTV – Camera Defective',
            'CCTV – Storage/Retention',
        ];

        foreach ($items as $name) {
            Item::firstOrCreate(
                ['name' => $name, 'category_id' => $category->id],
                [
                    'description' => 'CCTV monitoring item',
                    'priority' => 'medium',
                    'is_active' => true,
                ]
            );
        }
    }

    public function down(): void
    {
        $category = Category::where('name', 'CCTV')->first();

        if ($category) {
            Item::where('category_id', $category->id)->delete();
            $category->delete();
        }
    }
};
