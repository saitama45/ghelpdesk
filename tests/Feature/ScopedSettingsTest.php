<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\Setting;
use App\Services\StoreReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopedSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_settings_prefer_node_then_department_then_legacy_then_global(): void
    {
        Setting::set('business_start_time', '08:00', 'business_hours');
        $this->assertSame('08:00', Setting::getScoped('business_start_time', '09:00'));

        Setting::set('business_end_time_support__field', '16:00', 'business_hours');
        $this->assertSame('16:00', Setting::getScoped('business_end_time', '17:00', legacyScope: 'Support > Field'));

        Setting::set('working_days', '[1,2,3]', 'business_hours');
        Setting::set('working_days_department_7', '[1,2,3,4]', 'business_hours');
        $this->assertSame('[1,2,3,4]', Setting::getScoped('working_days', '[1]', departmentId: 7, legacyScope: 'Support > Field'));

        Setting::set('threshold_green_max', '1', 'thresholds');
        Setting::set('threshold_green_max_department_7', '2', 'thresholds');
        Setting::set('threshold_green_max_node_15', '3', 'thresholds');
        $this->assertSame('3', Setting::getScoped('threshold_green_max', '0', departmentId: 7, departmentNodeId: 15, legacyScope: 'Support > Field'));
    }

    public function test_store_health_thresholds_use_node_and_department_scopes(): void
    {
        $department = Department::create(['name' => 'Support']);
        $node = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Field',
        ]);

        Setting::set('threshold_green_max', '1', 'thresholds');
        Setting::set("threshold_green_max_department_{$department->id}", '2', 'thresholds');
        Setting::set("threshold_green_max_node_{$node->id}", '3', 'thresholds');

        $service = app(StoreReportService::class);

        $departmentData = $service->getStoreHealthData([
            'department_id' => $department->id,
            'user_id' => 'all',
            'store_id' => 'all',
            'as_of_date' => '2026-06-02',
        ]);

        $nodeData = $service->getStoreHealthData([
            'department_id' => $department->id,
            'department_node_id' => $node->id,
            'user_id' => 'all',
            'store_id' => 'all',
            'as_of_date' => '2026-06-02',
        ]);

        $this->assertSame('2', $departmentData['thresholds']['threshold_green_max']);
        $this->assertSame('3', $nodeData['thresholds']['threshold_green_max']);
    }
}
