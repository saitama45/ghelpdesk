<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\NpcStatusController;
use App\Http\Controllers\CctvMonitoringController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

// Serve storage files directly (bypassing the need for symlinks)
Route::get('/serve-storage/{path}', function (string $path) {
    // Normalize path for physical file lookup
    $cleanPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $filePath = storage_path('app' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $cleanPath);
    
    if (!file_exists($filePath) || !is_file($filePath)) {
        abort(404);
    }
    
    $mimeType = \Illuminate\Support\Facades\File::mimeType($filePath);
    
    return response()->file($filePath, [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=86400'
    ]);
})->where('path', '.*')->name('storage.file');

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Landing', [
        'canLogin' => Route::has('login'),
    ]);
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
        ->middleware(['verified'])
        ->name('dashboard');
    Route::get('/dashboard/export', [\App\Http\Controllers\DashboardController::class, 'export'])
        ->name('dashboard.export');
    Route::get('/global-search', [\App\Http\Controllers\GlobalSearchController::class, 'search'])->name('global-search');
    Route::get('/dtr', [\App\Http\Controllers\AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/logs', [\App\Http\Controllers\AttendanceController::class, 'logs'])->name('attendance.logs');
    Route::post('/attendance/log', [\App\Http\Controllers\AttendanceController::class, 'log'])->name('attendance.log');
    Route::get('npc-statuses/{npcStatus}/attachments/{type}', [NpcStatusController::class, 'downloadAttachment'])
        ->whereIn('type', ['seal', 'registration'])
        ->name('npc-statuses.attachments.download');
    Route::post('npc-statuses/{npcStatus}/attachments', [NpcStatusController::class, 'storeAttachment'])->name('npc-statuses.attachments.store');
    Route::get('npc-status-attachments/{attachment}/download', [NpcStatusController::class, 'downloadStatusAttachment'])->name('npc-status-attachments.download');
    Route::delete('npc-status-attachments/{attachment}', [NpcStatusController::class, 'destroyAttachment'])->name('npc-status-attachments.destroy');
    Route::put('npc-statuses/{npcStatus}/workflow', [NpcStatusController::class, 'updateWorkflow'])->name('npc-statuses.workflow.update');
    Route::put('npc-statuses/{npcStatus}/stores', [NpcStatusController::class, 'syncStores'])->name('npc-statuses.stores.update');
    Route::post('stores/{store}/cctv-seal-notice', [NpcStatusController::class, 'storeCctvSealNotice'])->name('stores.cctv-seal-notice.store');
    Route::get('stores/{store}/cctv-seal-notice', [NpcStatusController::class, 'downloadCctvSealNotice'])->name('stores.cctv-seal-notice.download');
    Route::resource('npc-statuses', NpcStatusController::class)
        ->parameters(['npc-statuses' => 'npcStatus'])
        ->except(['show', 'create', 'edit']);

    // CCTV Monitoring
    Route::get('cctv-monitoring/import-template', [CctvMonitoringController::class, 'importTemplate'])->name('cctv-monitoring.import-template');
    Route::post('cctv-monitoring/import', [CctvMonitoringController::class, 'import'])->name('cctv-monitoring.import');
    Route::get('stores/{store}/cctv-units', [CctvMonitoringController::class, 'unitsSearch'])->name('cctv-monitoring.units.search');
    Route::post('cctv-systems/{cctvSystem}/inspections', [CctvMonitoringController::class, 'storeInspection'])->name('cctv-monitoring.inspections.store');
    Route::put('cctv-inspections/{cctvInspection}', [CctvMonitoringController::class, 'updateInspection'])->name('cctv-monitoring.inspections.update');
    Route::get('cctv-inspections/{cctvInspection}', [CctvMonitoringController::class, 'showInspection'])->name('cctv-monitoring.inspections.show');
    Route::delete('cctv-inspections/{cctvInspection}', [CctvMonitoringController::class, 'destroyInspection'])->name('cctv-monitoring.inspections.destroy');
    Route::resource('cctv-monitoring', CctvMonitoringController::class)
        ->parameters(['cctv-monitoring' => 'cctvSystem'])
        ->except(['show', 'create', 'edit']);

    // WIGS — Wildly Important Goals (Yardstick / PCF / PAF)
    Route::prefix('wigs')->name('wigs.')->group(function () {
        Route::get('/', [\App\Http\Controllers\WigsController::class, 'index'])->name('index');
        Route::put('yardstick', [\App\Http\Controllers\WigsController::class, 'saveYardstick'])->name('yardstick.save');
        Route::get('pcf/{pcf}', [\App\Http\Controllers\WigsController::class, 'showPcf'])->name('pcf.show');
        Route::post('pcf', [\App\Http\Controllers\WigsController::class, 'storePcf'])->name('pcf.store');
        Route::put('pcf/{pcf}', [\App\Http\Controllers\WigsController::class, 'updatePcf'])->name('pcf.update');
        Route::post('pcf/{pcf}/confirm', [\App\Http\Controllers\WigsController::class, 'confirmPcf'])->name('pcf.confirm');
        Route::put('pcf/{pcf}/grades', [\App\Http\Controllers\WigsController::class, 'gradePcf'])->name('pcf.grade');
        Route::delete('pcf/{pcf}', [\App\Http\Controllers\WigsController::class, 'destroyPcf'])->name('pcf.destroy');
    });

    // Mall Hookup — daily POS auto-sending compliance monitoring
    Route::prefix('mall-hookups')->name('mall-hookups.')->group(function () {
        Route::get('/', [\App\Http\Controllers\MallHookupController::class, 'index'])->name('index');
        Route::get('import-template', [\App\Http\Controllers\MallHookupController::class, 'importTemplate'])->name('import-template');
        Route::post('import', [\App\Http\Controllers\MallHookupController::class, 'importLogs'])->name('import');
        Route::get('export', [\App\Http\Controllers\MallHookupController::class, 'export'])->name('export');
        Route::post('daily', [\App\Http\Controllers\MallHookupController::class, 'saveDailyLogs'])->name('daily.save');
        Route::put('locations/{mallHookup}', [\App\Http\Controllers\MallHookupController::class, 'updateHookup'])->name('locations.update');
    });

    Route::resource('users', UserController::class);
    Route::put('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    
    Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit']);
    Route::post('companies/switch', [CompanyController::class, 'switch'])->name('companies.switch');
    Route::resource('companies', CompanyController::class)->except(['show', 'create', 'edit']);
    Route::resource('departments', DepartmentController::class)->except(['show', 'create', 'edit']);
    Route::post('departments/{department}/nodes', [DepartmentController::class, 'storeNode'])->name('departments.nodes.store');
    Route::put('department-nodes/{node}', [DepartmentController::class, 'updateNode'])->name('departments.nodes.update');
    Route::delete('department-nodes/{node}', [DepartmentController::class, 'destroyNode'])->name('departments.nodes.destroy');
    
    Route::put('departments/users/reorder', [DepartmentController::class, 'reorderUsers'])->name('departments.users.reorder');
    Route::put('departments/structure/reorder', [DepartmentController::class, 'reorderStructure'])->name('departments.structure.reorder');
    Route::put('departments/users/{user}/placement', [DepartmentController::class, 'updateUserPlacement'])->name('departments.users.placement');
    Route::delete('departments/users/{user}/placement', [DepartmentController::class, 'destroyUserPlacement'])->name('departments.users.remove-placement');
    Route::post('departments/users/vacant', [DepartmentController::class, 'storeVacant'])->name('departments.users.vacant.store');
    Route::put('departments/users/vacant/{user}', [DepartmentController::class, 'updateVacant'])->name('departments.users.vacant.update');
    Route::delete('departments/users/vacant/{user}', [DepartmentController::class, 'destroyVacant'])->name('departments.users.vacant.destroy');
    Route::resource('clusters', \App\Http\Controllers\ClusterController::class)->except(['show', 'create', 'edit']);
    Route::post('clusters/{cluster}/assign-stores', [\App\Http\Controllers\ClusterController::class, 'assignStores'])->name('clusters.assign-stores');
    Route::get('categories/template', [\App\Http\Controllers\CategoryController::class, 'template'])->name('categories.template');
    Route::post('categories/import', [\App\Http\Controllers\CategoryController::class, 'import'])->name('categories.import');
    Route::get('sub-categories/template', [\App\Http\Controllers\SubCategoryController::class, 'template'])->name('sub-categories.template');
    Route::post('sub-categories/import', [\App\Http\Controllers\SubCategoryController::class, 'import'])->name('sub-categories.import');
    Route::get('items/template', [\App\Http\Controllers\ItemController::class, 'template'])->name('items.template');
    Route::post('items/import', [\App\Http\Controllers\ItemController::class, 'import'])->name('items.import');
    Route::get('items/export', [\App\Http\Controllers\ItemController::class, 'export'])->name('items.export');
    Route::resource('categories', \App\Http\Controllers\CategoryController::class)->except(['show', 'create', 'edit']);
    Route::resource('sub-categories', \App\Http\Controllers\SubCategoryController::class)->except(['show', 'create', 'edit']);
    Route::resource('items', \App\Http\Controllers\ItemController::class)->except(['show', 'create', 'edit']);
    Route::resource('request-types', \App\Http\Controllers\RequestTypeController::class)->except(['show', 'create', 'edit']);
    Route::put('request-types/{requestType}/schema', [\App\Http\Controllers\RequestTypeController::class, 'updateSchema'])->name('request-types.schema');
    Route::resource('form-builder', \App\Http\Controllers\FormBuilderController::class)->except(['show', 'create', 'edit']);
    Route::put('form-builder/{form_builder}/schema', [\App\Http\Controllers\FormBuilderController::class, 'updateSchema'])->name('form-builder.schema');

    // Dynamic Forms
    Route::get('forms', [\App\Http\Controllers\DynamicFormController::class, 'list'])->name('dynamic-form.list');
    Route::get('forms/{slug}', [\App\Http\Controllers\DynamicFormController::class, 'index'])->name('dynamic-form.index');

    // Copy Transfer Routes
    Route::get('copy/targets', [\App\Http\Controllers\CopyRecordController::class, 'targets'])->name('copy.targets');
    Route::post('copy/transfer', [\App\Http\Controllers\CopyRecordController::class, 'transfer'])->name('copy.transfer');
    Route::post('forms/{slug}', [\App\Http\Controllers\DynamicFormController::class, 'store'])->name('dynamic-form.store');
    Route::get('forms/{slug}/{id}', [\App\Http\Controllers\DynamicFormController::class, 'show'])->name('dynamic-form.show');
    Route::put('forms/{slug}/{id}', [\App\Http\Controllers\DynamicFormController::class, 'update'])->name('dynamic-form.update');
    Route::delete('forms/{slug}/{id}', [\App\Http\Controllers\DynamicFormController::class, 'destroy'])->name('dynamic-form.destroy');
    Route::post('forms/{slug}/{id}/approve', [\App\Http\Controllers\DynamicFormController::class, 'approve'])->name('dynamic-form.approve');
    Route::post('forms/{slug}/{id}/reject', [\App\Http\Controllers\DynamicFormController::class, 'reject'])->name('dynamic-form.reject');
    Route::post('forms/{slug}/{id}/remind', [\App\Http\Controllers\DynamicFormController::class, 'remind'])->name('dynamic-form.remind');
    Route::resource('pos-requests', \App\Http\Controllers\PosRequestController::class);
    Route::post('pos-requests/{pos_request}/approve', [\App\Http\Controllers\PosRequestController::class, 'approve'])->name('pos-requests.approve');
    Route::post('pos-requests/{pos_request}/reject', [\App\Http\Controllers\PosRequestController::class, 'reject'])->name('pos-requests.reject');
    Route::post('pos-requests/{pos_request}/remind', [\App\Http\Controllers\PosRequestController::class, 'remind'])->name('pos-requests.remind');
    Route::resource('sap-requests', \App\Http\Controllers\SapRequestController::class);
    Route::post('sap-requests/{sap_request}/approve', [\App\Http\Controllers\SapRequestController::class, 'approve'])->name('sap-requests.approve');
    Route::post('sap-requests/{sap_request}/reject', [\App\Http\Controllers\SapRequestController::class, 'reject'])->name('sap-requests.reject');
    Route::post('sap-requests/{sap_request}/remind', [\App\Http\Controllers\SapRequestController::class, 'remind'])->name('sap-requests.remind');
    Route::get('stock-ins/template', [\App\Http\Controllers\StockInController::class, 'template'])->name('stock-ins.template');
    Route::get('stock-ins/assets-with-stock', [\App\Http\Controllers\StockInController::class, 'assetsWithStock'])->name('stock-ins.assets-with-stock');
    Route::get('stock-ins/available-stock', [\App\Http\Controllers\StockInController::class, 'availableStock'])->name('stock-ins.available-stock');
    Route::post('stock-ins/import', [\App\Http\Controllers\StockInController::class, 'import'])->name('stock-ins.import');
    Route::post('stock-ins/{stock_in}/post', [\App\Http\Controllers\StockInController::class, 'post'])->name('stock-ins.post');
    Route::get('stock-ins/{stock_in}/print-barcodes', [\App\Http\Controllers\StockInController::class, 'printBarcodes'])->name('stock-ins.print-barcodes');
    Route::get('stock-ins/{stock_in}/print-qrcodes', [\App\Http\Controllers\StockInController::class, 'printQrcodes'])->name('stock-ins.print-qrcodes');
    Route::resource('stock-ins', \App\Http\Controllers\StockInController::class);

    Route::get('stock-transfers/available-stock', [\App\Http\Controllers\StockTransferController::class, 'availableStock'])->name('stock-transfers.available-stock');
    Route::get('stock-transfers/assets-with-stock', [\App\Http\Controllers\StockTransferController::class, 'assetsWithStock'])->name('stock-transfers.assets-with-stock');
    Route::post('stock-transfers/{stock_transfer}/post', [\App\Http\Controllers\StockTransferController::class, 'post'])->name('stock-transfers.post');
    Route::resource('stock-transfers', \App\Http\Controllers\StockTransferController::class);

    Route::post('stock-receivings/{stock_receiving}/post', [\App\Http\Controllers\StockReceivingController::class, 'post'])->name('stock-receivings.post');
    Route::post('stock-receivings/{stock_receiving}/decline', [\App\Http\Controllers\StockReceivingController::class, 'decline'])->name('stock-receivings.decline');
    Route::resource('stock-receivings', \App\Http\Controllers\StockReceivingController::class)->except(['create', 'edit', 'store']);

    Route::post('task-boards/{taskBoard}/restore', [\App\Http\Controllers\TaskBoardController::class, 'restore'])->name('task-boards.restore');
    Route::post('task-boards/{taskBoard}/star', [\App\Http\Controllers\TaskBoardController::class, 'toggleStar'])->name('task-boards.star');
    Route::post('task-boards/{taskBoard}/watch', [\App\Http\Controllers\TaskBoardController::class, 'toggleWatch'])->name('task-boards.watch');
    Route::post('task-boards/{taskBoard}/sync-project', [\App\Http\Controllers\TaskBoardController::class, 'syncProject'])->name('task-boards.sync-project');
    Route::post('task-boards/monthly-generate', [\App\Http\Controllers\TaskBoardController::class, 'generateMonthly'])->name('task-boards.monthly-generate');
    Route::post('task-boards/{taskBoard}/members', [\App\Http\Controllers\TaskBoardController::class, 'storeMember'])->name('task-boards.members.store');
    Route::put('task-boards/{taskBoard}/members/{user}', [\App\Http\Controllers\TaskBoardController::class, 'updateMember'])->name('task-boards.members.update');
    Route::delete('task-boards/{taskBoard}/members/{user}', [\App\Http\Controllers\TaskBoardController::class, 'destroyMember'])->name('task-boards.members.destroy');
    Route::post('task-boards/{taskBoard}/cards', [\App\Http\Controllers\TaskCardController::class, 'store'])->name('task-boards.cards.store');
    Route::post('task-boards/{taskBoard}/labels', [\App\Http\Controllers\TaskCardController::class, 'storeLabel'])->name('task-boards.labels.store');
    Route::post('task-boards/{taskBoard}/columns', [\App\Http\Controllers\TaskCardController::class, 'storeColumn'])->name('task-boards.columns.store');
    Route::post('task-boards/{taskBoard}/columns/reorder', [\App\Http\Controllers\TaskCardController::class, 'reorderColumns'])->name('task-boards.columns.reorder');
    Route::resource('task-boards', \App\Http\Controllers\TaskBoardController::class)
        ->parameters(['task-boards' => 'taskBoard'])
        ->except(['create', 'edit']);
    Route::put('task-labels/{taskLabel}', [\App\Http\Controllers\TaskCardController::class, 'updateLabel'])->name('task-labels.update');
    Route::delete('task-labels/{taskLabel}', [\App\Http\Controllers\TaskCardController::class, 'destroyLabel'])->name('task-labels.destroy');
    Route::put('task-columns/{taskBoardColumn}', [\App\Http\Controllers\TaskCardController::class, 'updateColumn'])->name('task-columns.update');
    Route::delete('task-columns/{taskBoardColumn}', [\App\Http\Controllers\TaskCardController::class, 'destroyColumn'])->name('task-columns.destroy');
    Route::put('task-cards/{taskCard}', [\App\Http\Controllers\TaskCardController::class, 'update'])->name('task-cards.update');
    Route::post('task-cards/{taskCard}/move', [\App\Http\Controllers\TaskCardController::class, 'move'])->name('task-cards.move');
    Route::post('task-cards/{taskCard}/archive', [\App\Http\Controllers\TaskCardController::class, 'archive'])->name('task-cards.archive');
    Route::post('task-cards/{taskCard}/restore', [\App\Http\Controllers\TaskCardController::class, 'restore'])->name('task-cards.restore');
    Route::delete('task-cards/{taskCard}', [\App\Http\Controllers\TaskCardController::class, 'destroy'])->name('task-cards.destroy');
    Route::post('task-cards/{taskCard}/watch', [\App\Http\Controllers\TaskCardController::class, 'toggleWatch'])->name('task-cards.watch');
    Route::post('task-cards/{taskCard}/checklists', [\App\Http\Controllers\TaskCardController::class, 'storeChecklist'])->name('task-cards.checklists.store');
    Route::post('task-cards/{taskCard}/comments', [\App\Http\Controllers\TaskCardController::class, 'storeComment'])->name('task-cards.comments.store');
    Route::post('task-cards/{taskCard}/attachments', [\App\Http\Controllers\TaskCardController::class, 'storeAttachment'])->name('task-cards.attachments.store');
    Route::put('task-checklists/{taskChecklist}', [\App\Http\Controllers\TaskCardController::class, 'updateChecklist'])->name('task-checklists.update');
    Route::delete('task-checklists/{taskChecklist}', [\App\Http\Controllers\TaskCardController::class, 'destroyChecklist'])->name('task-checklists.destroy');
    Route::post('task-checklists/{taskChecklist}/duplicate', [\App\Http\Controllers\TaskCardController::class, 'duplicateChecklist'])->name('task-checklists.duplicate');
    Route::post('task-checklists/{taskChecklist}/items', [\App\Http\Controllers\TaskCardController::class, 'storeChecklistItem'])->name('task-checklists.items.store');
    Route::put('task-checklist-items/{taskChecklistItem}', [\App\Http\Controllers\TaskCardController::class, 'updateChecklistItem'])->name('task-checklist-items.update');
    Route::delete('task-checklist-items/{taskChecklistItem}', [\App\Http\Controllers\TaskCardController::class, 'destroyChecklistItem'])->name('task-checklist-items.destroy');
    Route::post('task-checklist-items/{taskChecklistItem}/duplicate', [\App\Http\Controllers\TaskCardController::class, 'duplicateChecklistItem'])->name('task-checklist-items.duplicate');
    Route::delete('task-card-comments/{taskCardComment}', [\App\Http\Controllers\TaskCardController::class, 'destroyComment'])->name('task-card-comments.destroy');
    Route::delete('task-card-attachments/{taskCardAttachment}', [\App\Http\Controllers\TaskCardController::class, 'destroyAttachment'])->name('task-card-attachments.destroy');
    Route::get('stores/template', [\App\Http\Controllers\StoreController::class, 'template'])->name('stores.template');
    Route::post('stores/import', [\App\Http\Controllers\StoreController::class, 'import'])->name('stores.import');
    Route::get('stores/{store}/details', [\App\Http\Controllers\StoreController::class, 'details'])->name('stores.details');
    Route::post('stores/{store}/blueprints', [\App\Http\Controllers\StoreController::class, 'uploadBlueprint'])->name('stores.blueprints.store');
    Route::get('stores/{store}/blueprints/{blueprint}', [\App\Http\Controllers\StoreController::class, 'downloadBlueprint'])->name('stores.blueprints.download');
    Route::delete('stores/{store}/blueprints/{blueprint}', [\App\Http\Controllers\StoreController::class, 'destroyBlueprint'])->name('stores.blueprints.destroy');
    Route::resource('stores', \App\Http\Controllers\StoreController::class)->except(['show', 'create', 'edit']);
    Route::resource('vendors', \App\Http\Controllers\VendorController::class)->except(['show', 'create', 'edit']);
    Route::resource('activity-templates', \App\Http\Controllers\ActivityTemplateController::class)->except(['show', 'create', 'edit']);
    Route::post('reference-options', [\App\Http\Controllers\ReferenceOptionController::class, 'store'])->name('reference-options.store');
    Route::put('reference-options/{referenceOption}', [\App\Http\Controllers\ReferenceOptionController::class, 'update'])->name('reference-options.update');
    Route::delete('reference-options/{referenceOption}', [\App\Http\Controllers\ReferenceOptionController::class, 'destroy'])->name('reference-options.destroy');
    Route::get('schedules/template', [\App\Http\Controllers\ScheduleController::class, 'template'])->name('schedules.template');
    Route::post('schedules/import', [\App\Http\Controllers\ScheduleController::class, 'import'])->name('schedules.import');
    Route::get('schedules/report-data', [\App\Http\Controllers\ScheduleController::class, 'reportData'])->name('schedules.report-data');
    Route::get('schedules/missing-schedules', [\App\Http\Controllers\ScheduleController::class, 'missingSchedules'])->name('schedules.missing-schedules');
    Route::get('schedules/complete-schedules', [\App\Http\Controllers\ScheduleController::class, 'completeSchedules'])->name('schedules.complete-schedules');
    Route::get('schedules/duplicates', [\App\Http\Controllers\ScheduleController::class, 'duplicates'])->name('schedules.duplicates');
    Route::delete('schedules/duplicates', [\App\Http\Controllers\ScheduleController::class, 'destroyDuplicates'])->name('schedules.duplicates.destroy');
    Route::post('schedules/{schedule}/actual-times', [\App\Http\Controllers\ScheduleController::class, 'updateActualTimes'])->name('schedules.actual-times.update');
    Route::post('schedules/{schedule}/actual-time-requests', [\App\Http\Controllers\ScheduleController::class, 'storeActualTimeRequest'])->name('schedules.actual-time-requests.store');
    Route::post('schedules/{schedule}/change-requests', [\App\Http\Controllers\ScheduleController::class, 'storeChangeRequest'])->name('schedules.change-requests.store');
    Route::post('schedule-change-requests/{scheduleChangeRequest}/approve', [\App\Http\Controllers\ScheduleController::class, 'approveChangeRequest'])->name('schedule-change-requests.approve');
    Route::post('schedule-change-requests/{scheduleChangeRequest}/reject', [\App\Http\Controllers\ScheduleController::class, 'rejectChangeRequest'])->name('schedule-change-requests.reject');
    Route::delete('schedule-change-requests/{scheduleChangeRequest}', [\App\Http\Controllers\ScheduleController::class, 'cancelChangeRequest'])->name('schedule-change-requests.cancel');
    Route::resource('schedules', \App\Http\Controllers\ScheduleController::class)->except(['show', 'create', 'edit']);
    Route::get('schedules/export/pdf', [\App\Http\Controllers\ScheduleExportController::class, 'pdf'])->name('schedules.export.pdf');
    Route::get('tickets/data/categories', [\App\Http\Controllers\TicketController::class, 'getCategories'])->name('tickets.data.categories');
    Route::get('tickets/data/subcategories', [\App\Http\Controllers\TicketController::class, 'getSubCategories'])->name('tickets.data.subcategories');
    Route::get('tickets/data/items', [\App\Http\Controllers\TicketController::class, 'getItems'])->name('tickets.data.items');
    Route::get('tickets/data/requester', [\App\Http\Controllers\TicketController::class, 'requesterTickets'])->name('tickets.data.requester');
    Route::get('assets/template', [\App\Http\Controllers\AssetController::class, 'template'])->name('assets.template');
    Route::post('assets/import', [\App\Http\Controllers\AssetController::class, 'import'])->name('assets.import');
    Route::get('assets/generate-code', [\App\Http\Controllers\AssetController::class, 'generateCode'])->name('assets.generate-code');
    Route::resource('assets', \App\Http\Controllers\AssetController::class)->except(['show', 'create', 'edit']);
    Route::post('tickets/sync', [\App\Http\Controllers\TicketController::class, 'sync'])->name('tickets.sync');

    Route::post('tickets/bulk-update', [\App\Http\Controllers\TicketController::class, 'bulkUpdate'])->name('tickets.bulk-update');
    Route::post('tickets/bulk-response', [\App\Http\Controllers\TicketController::class, 'bulkResponse'])->name('tickets.bulk-response');
    Route::post('tickets/bulk-archive', [\App\Http\Controllers\TicketController::class, 'bulkArchive'])->name('tickets.bulk-archive');
    Route::post('tickets/bulk-child', [\App\Http\Controllers\TicketController::class, 'bulkStoreChild'])->name('tickets.bulk-child');
    Route::post('tickets/merge', [\App\Http\Controllers\TicketController::class, 'merge'])->name('tickets.merge');
    Route::get('tickets/export', [\App\Http\Controllers\TicketController::class, 'export'])->name('tickets.export');
    Route::resource('tickets', \App\Http\Controllers\TicketController::class);
    Route::post('tickets/{ticket}/split', [\App\Http\Controllers\TicketController::class, 'split'])->name('tickets.split');
    Route::post('tickets/{ticket}/child', [\App\Http\Controllers\TicketController::class, 'storeChild'])->name('tickets.store-child');
    Route::post('tickets/{ticket}/assign-schedule', [\App\Http\Controllers\TicketController::class, 'assignSchedule'])->name('tickets.assign-schedule');
    Route::put('tickets/{ticket}/update-schedule', [\App\Http\Controllers\TicketController::class, 'updateSchedule'])->name('tickets.update-schedule');
    Route::put('tickets/{ticket}/ccs', [\App\Http\Controllers\TicketController::class, 'syncCcs'])->name('tickets.sync-ccs');
    Route::post('tickets/{ticket}/duplicate', [\App\Http\Controllers\TicketController::class, 'duplicate'])->name('tickets.duplicate');
    Route::post('tickets/{ticket}/comments', [\App\Http\Controllers\TicketController::class, 'storeComment'])->name('tickets.comments.store');
    Route::post('tickets/{ticket}/attachments', [\App\Http\Controllers\TicketController::class, 'storeAttachment'])->name('tickets.attachments.store');
    Route::get('tickets/{ticket}/assets', [\App\Http\Controllers\TicketAssetController::class, 'index'])->name('tickets.assets.index');
    Route::post('tickets/{ticket}/assets', [\App\Http\Controllers\TicketAssetController::class, 'store'])->name('tickets.assets.store');
    Route::put('tickets/{ticket}/assets/{ticketAsset}', [\App\Http\Controllers\TicketAssetController::class, 'update'])->name('tickets.assets.update');
    Route::delete('tickets/{ticket}/assets/{ticketAsset}', [\App\Http\Controllers\TicketAssetController::class, 'destroy'])->name('tickets.assets.destroy');
    Route::get('attachments/{attachment}/download', [\App\Http\Controllers\TicketController::class, 'downloadAttachment'])->name('tickets.attachments.download');
    
    Route::get('settings', [\App\Http\Controllers\SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/test-imap', [\App\Http\Controllers\SettingsController::class, 'testImap'])->name('settings.test-imap');
    Route::get('settings/ticket-archive', [\App\Http\Controllers\TicketArchiveController::class, 'index'])->name('ticket-archive.index');
    Route::post('settings/ticket-archive/bulk-restore', [\App\Http\Controllers\TicketArchiveController::class, 'bulkRestore'])->name('ticket-archive.bulk-restore');
    Route::delete('settings/ticket-archive/bulk-purge', [\App\Http\Controllers\TicketArchiveController::class, 'bulkPurge'])->name('ticket-archive.bulk-purge');
    Route::post('settings/ticket-archive/{ticket}/restore', [\App\Http\Controllers\TicketArchiveController::class, 'restore'])->name('ticket-archive.restore');
    Route::delete('settings/ticket-archive/{ticket}/purge', [\App\Http\Controllers\TicketArchiveController::class, 'purge'])->name('ticket-archive.purge');
    Route::resource('canned-messages', \App\Http\Controllers\CannedMessageController::class)->except(['show', 'create', 'edit']);

    // Leadership Points Settings
    Route::get('leadership-points', [\App\Http\Controllers\LeadershipPointsController::class, 'index'])->name('leadership-points.index');
    Route::put('leadership-points', [\App\Http\Controllers\LeadershipPointsController::class, 'update'])->name('leadership-points.update');
    Route::post('leadership-points/quests', [\App\Http\Controllers\LeadershipPointsController::class, 'storeQuest'])->name('leadership-points.quests.store');
    Route::put('leadership-points/quests/{quest}', [\App\Http\Controllers\LeadershipPointsController::class, 'updateQuest'])->name('leadership-points.quests.update');
    Route::delete('leadership-points/quests/{quest}', [\App\Http\Controllers\LeadershipPointsController::class, 'destroyQuest'])->name('leadership-points.quests.destroy');

    Route::get('reports/store-health', [\App\Http\Controllers\StoreReportController::class, 'index'])->name('reports.store-health');
    Route::get('reports/store-health/pdf', [\App\Http\Controllers\StoreReportController::class, 'pdf'])->name('reports.store-health.pdf');
    Route::get('reports/store-health/{store}/tickets', [\App\Http\Controllers\StoreReportController::class, 'getTickets'])->name('reports.store-health.tickets');
    Route::get('reports/store-health/sector/{sector}/tickets', [\App\Http\Controllers\StoreReportController::class, 'getSectorTickets'])->name('reports.store-health.sector-tickets');
    Route::get('reports/sla-performance', [\App\Http\Controllers\SlaReportController::class, 'index'])->name('reports.sla-performance');
    Route::get('reports/sla-performance/pdf', [\App\Http\Controllers\SlaReportController::class, 'pdf'])->name('reports.sla-performance.pdf');
    Route::get('reports/sla-performance/tickets', [\App\Http\Controllers\SlaReportController::class, 'getTickets'])->name('reports.sla-performance.tickets');
    Route::get('reports/assignee-performance', [\App\Http\Controllers\AssigneePerformanceReportController::class, 'index'])->name('reports.assignee-performance');
    Route::get('reports/assignee-performance/pdf', [\App\Http\Controllers\AssigneePerformanceReportController::class, 'pdf'])->name('reports.assignee-performance.pdf');
    // Service Vehicle Trips
    Route::get('service-vehicle-trips/conflict-check', [\App\Http\Controllers\ServiceVehicleTripController::class, 'detectConflict'])->name('service-vehicle-trips.conflict-check');
    Route::patch('service-vehicle-trips/{serviceVehicleTrip}/approve',  [\App\Http\Controllers\ServiceVehicleTripController::class, 'approve' ])->name('service-vehicle-trips.approve');
    Route::patch('service-vehicle-trips/{serviceVehicleTrip}/reject',   [\App\Http\Controllers\ServiceVehicleTripController::class, 'reject'  ])->name('service-vehicle-trips.reject');
    Route::patch('service-vehicle-trips/{serviceVehicleTrip}/start',    [\App\Http\Controllers\ServiceVehicleTripController::class, 'start'   ])->name('service-vehicle-trips.start');
    Route::patch('service-vehicle-trips/{serviceVehicleTrip}/complete', [\App\Http\Controllers\ServiceVehicleTripController::class, 'complete'])->name('service-vehicle-trips.complete');
    Route::patch('service-vehicle-trips/{serviceVehicleTrip}/cancel',   [\App\Http\Controllers\ServiceVehicleTripController::class, 'cancel'  ])->name('service-vehicle-trips.cancel');
    Route::resource('service-vehicle-trips', \App\Http\Controllers\ServiceVehicleTripController::class)->except(['create', 'edit']);
    Route::resource('service-vehicles', \App\Http\Controllers\ServiceVehicleController::class)->except(['create', 'edit', 'show']);

    Route::get('reports/inventory', [\App\Http\Controllers\InventoryReportController::class, 'index'])->name('reports.inventory');
    Route::get('reports/inventory/movement', [\App\Http\Controllers\InventoryReportController::class, 'movement'])->name('reports.inventory.movement');
    Route::get('reports/inventory/assets/search', [\App\Http\Controllers\InventoryReportController::class, 'assetsSearch'])->name('reports.inventory.assets-search');
    Route::get('reports/inventory/{asset}/history', [\App\Http\Controllers\InventoryReportController::class, 'history'])->name('reports.inventory.history');
    Route::get('reports/inventory/{asset}/ticket-activity', [\App\Http\Controllers\InventoryReportController::class, 'ticketActivity'])->name('reports.inventory.ticket-activity');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::middleware([\App\Http\Middleware\UpdateUserPresence::class])->group(function () {
        Route::get('/presence', function () {
            if (!auth()->user()->can('presence.view')) abort(403);
            return \Inertia\Inertia::render('Presence/Index');
        })->name('presence.index');
        Route::post('/presence/status', [\App\Http\Controllers\PresenceController::class, 'updateStatus'])->name('presence.update');
        Route::get('/presence/active-users', [\App\Http\Controllers\PresenceController::class, 'getActiveUsers'])->name('presence.active-users');
        Route::get('/presence/user-stats/{user}', [\App\Http\Controllers\PresenceController::class, 'getUserStats'])->name('presence.user-stats');
    });

    // Knowledge Base
    Route::resource('kb-articles', \App\Http\Controllers\KbArticleController::class)->names([
        'index' => 'kb-articles.index',
        'create' => 'kb-articles.create',
        'store' => 'kb-articles.store',
        'edit' => 'kb-articles.edit',
        'update' => 'kb-articles.update',
        'destroy' => 'kb-articles.destroy',
    ]);
    Route::get('/kb-categories/search', [\App\Http\Controllers\KbArticleController::class, 'getCategories'])->name('kb-categories.search');
    Route::delete('/kb-categories/{kb_category}', [\App\Http\Controllers\KbArticleController::class, 'destroyCategory'])->name('kb-categories.destroy');
    Route::get('/knowledge-base', [\App\Http\Controllers\KbArticleController::class, 'portal'])->name('knowledge-base.portal');
    Route::get('/knowledge-base/{kb_article:slug}', [\App\Http\Controllers\KbArticleController::class, 'show'])->name('knowledge-base.show');
    Route::post('/knowledge-base/{kb_article}/feedback', [\App\Http\Controllers\KbArticleController::class, 'submitFeedback'])->name('knowledge-base.feedback');

    Route::get('/notifications/summary', [\App\Http\Controllers\NotificationController::class, 'summary'])->name('notifications.summary');

    // NSO Project Tracker
    Route::post('projects/{project}/task-board', [\App\Http\Controllers\TaskBoardController::class, 'openProjectBoard'])->name('projects.task-board');
    Route::post('projects/{project}/duplicate', [\App\Http\Controllers\ProjectController::class, 'duplicate'])->name('projects.duplicate');
    Route::resource('projects', \App\Http\Controllers\ProjectController::class);
    Route::post('projects/{project}/apply-templates', [\App\Http\Controllers\ProjectTaskController::class, 'applyTemplates'])->name('projects.apply-templates');
    Route::delete('projects/{project}/milestone-tasks', [\App\Http\Controllers\ProjectTaskController::class, 'destroyMilestone'])->name('projects.milestones.destroy');
    Route::post('projects/tasks/gantt', [\App\Http\Controllers\ProjectTaskController::class, 'updateGantt'])->name('projects.tasks.gantt-update');
    Route::resource('projects-tasks', \App\Http\Controllers\ProjectTaskController::class)->only(['store', 'update', 'destroy']);
    Route::resource('projects-assets', \App\Http\Controllers\ProjectAssetController::class)->only(['store', 'update', 'destroy']);
    Route::resource('projects-team-members', \App\Http\Controllers\ProjectTeamMemberController::class)->only(['store', 'destroy']);

    // Payments & SOA Monitoring
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/', [\App\Http\Controllers\PaymentMonitoringController::class, 'index'])->name('index');

        // Renewals
        Route::get('renewals/import-template', [\App\Http\Controllers\PaymentMonitoringController::class, 'renewalImportTemplate'])->name('renewals.import-template');
        Route::post('renewals/import', [\App\Http\Controllers\PaymentMonitoringController::class, 'importRenewals'])->name('renewals.import');
        Route::post('renewals', [\App\Http\Controllers\PaymentMonitoringController::class, 'storeRenewal'])->name('renewals.store');
        Route::put('renewals/{renewal}', [\App\Http\Controllers\PaymentMonitoringController::class, 'updateRenewal'])->name('renewals.update');
        Route::delete('renewals/{renewal}', [\App\Http\Controllers\PaymentMonitoringController::class, 'destroyRenewal'])->name('renewals.destroy');

        // Invoices
        Route::get('invoices/import-template', [\App\Http\Controllers\PaymentMonitoringController::class, 'invoiceImportTemplate'])->name('invoices.import-template');
        Route::post('invoices/import', [\App\Http\Controllers\PaymentMonitoringController::class, 'importInvoices'])->name('invoices.import');
        Route::post('invoices', [\App\Http\Controllers\PaymentMonitoringController::class, 'storeInvoice'])->name('invoices.store');
        Route::put('invoices/{invoice}', [\App\Http\Controllers\PaymentMonitoringController::class, 'updateInvoice'])->name('invoices.update');
        Route::delete('invoices/{invoice}', [\App\Http\Controllers\PaymentMonitoringController::class, 'destroyInvoice'])->name('invoices.destroy');

        // Overpayments
        Route::post('overpayments', [\App\Http\Controllers\PaymentMonitoringController::class, 'storeOverpayment'])->name('overpayments.store');
        Route::delete('overpayments/{overpayment}', [\App\Http\Controllers\PaymentMonitoringController::class, 'destroyOverpayment'])->name('overpayments.destroy');

        // Weekly Plans
        Route::get('weekly-plans/import-template', [\App\Http\Controllers\PaymentMonitoringController::class, 'weeklyPlanImportTemplate'])->name('weekly-plans.import-template');
        Route::post('weekly-plans/import', [\App\Http\Controllers\PaymentMonitoringController::class, 'importWeeklyPlans'])->name('weekly-plans.import');
        Route::post('weekly-plans', [\App\Http\Controllers\PaymentMonitoringController::class, 'storeWeeklyPlan'])->name('weekly-plans.store');
        Route::put('weekly-plans/{weekly_plan}', [\App\Http\Controllers\PaymentMonitoringController::class, 'updateWeeklyPlan'])->name('weekly-plans.update');
        Route::delete('weekly-plans/{weekly_plan}', [\App\Http\Controllers\PaymentMonitoringController::class, 'destroyWeeklyPlan'])->name('weekly-plans.destroy');

        // Connectivity Monitoring (Offices / Stores)
        Route::get('services/import-template', [\App\Http\Controllers\PaymentMonitoringController::class, 'connectivityImportTemplate'])->name('services.import-template');
        Route::post('services/import', [\App\Http\Controllers\PaymentMonitoringController::class, 'importConnectivity'])->name('services.import');
        Route::put('locations/{store}', [\App\Http\Controllers\PaymentMonitoringController::class, 'updateLocation'])->name('locations.update');
        Route::put('locations/{store}/services', [\App\Http\Controllers\PaymentMonitoringController::class, 'syncServices'])->name('locations.services.sync');
        Route::post('services', [\App\Http\Controllers\PaymentMonitoringController::class, 'storeService'])->name('services.store');
        Route::put('services/{service}', [\App\Http\Controllers\PaymentMonitoringController::class, 'updateService'])->name('services.update');
        Route::delete('services/{service}', [\App\Http\Controllers\PaymentMonitoringController::class, 'destroyService'])->name('services.destroy');

        // Records / Approval
        Route::post('records', [\App\Http\Controllers\PaymentMonitoringController::class, 'submitRecord'])->name('records.submit');
        Route::post('records/{record}/approve', [\App\Http\Controllers\PaymentMonitoringController::class, 'approveRecord'])->name('records.approve');
        Route::post('records/{record}/reject', [\App\Http\Controllers\PaymentMonitoringController::class, 'rejectRecord'])->name('records.reject');
        Route::post('records/{record}/mark-paid', [\App\Http\Controllers\PaymentMonitoringController::class, 'markPaid'])->name('records.mark-paid');

        // Reminders
        Route::post('{type}/{id}/remind', [\App\Http\Controllers\PaymentMonitoringController::class, 'sendManualReminder'])->name('remind');

        // Settings
        Route::put('settings', [\App\Http\Controllers\PaymentMonitoringController::class, 'updateSettings'])->name('settings.update');
    });

    // Loyalty Stamps Monitoring
    Route::prefix('stamps')->name('stamps.')->group(function () {
        Route::get('/', [\App\Http\Controllers\StampController::class, 'index'])->name('index');
        Route::get('assets-at-location', [\App\Http\Controllers\StampController::class, 'assetsAtLocation'])->name('assets-at-location');

        // Customers
        Route::post('customers', [\App\Http\Controllers\StampController::class, 'storeCustomer'])->name('customers.store');
        Route::put('customers/{customer}', [\App\Http\Controllers\StampController::class, 'updateCustomer'])->name('customers.update');
        Route::delete('customers/{customer}', [\App\Http\Controllers\StampController::class, 'destroyCustomer'])->name('customers.destroy');

        // Programs
        Route::post('programs', [\App\Http\Controllers\StampController::class, 'storeProgram'])->name('programs.store');
        Route::put('programs/{program}', [\App\Http\Controllers\StampController::class, 'updateProgram'])->name('programs.update');
        Route::delete('programs/{program}', [\App\Http\Controllers\StampController::class, 'destroyProgram'])->name('programs.destroy');

        // Cards & stamps
        Route::post('cards', [\App\Http\Controllers\StampController::class, 'storeCard'])->name('cards.store');
        Route::delete('cards/{card}', [\App\Http\Controllers\StampController::class, 'destroyCard'])->name('cards.destroy');
        Route::get('cards/{card}/entries', [\App\Http\Controllers\StampController::class, 'cardEntries'])->name('cards.entries');
        Route::post('cards/{card}/add-stamps', [\App\Http\Controllers\StampController::class, 'addStamps'])->name('cards.add-stamps');
        Route::post('cards/{card}/record-purchase', [\App\Http\Controllers\StampController::class, 'recordPurchase'])->name('cards.record-purchase');
        Route::post('cards/{card}/redeem', [\App\Http\Controllers\StampController::class, 'redeem'])->name('cards.redeem');
    });
});

// Public Routes (No Auth)
Route::get('/attachments/download', [App\Http\Controllers\AttachmentController::class, 'download'])->name('attachments.download');
Route::get('/public/pos-requests/create', [App\Http\Controllers\PublicPosRequestController::class, 'create'])->name('public.pos-requests.create');
Route::post('/public/pos-requests', [App\Http\Controllers\PublicPosRequestController::class, 'store'])->name('public.pos-requests.store');
Route::get('/public/sap-requests/create', [App\Http\Controllers\PublicSapRequestController::class, 'create'])->name('public.sap-requests.create');
Route::post('/public/sap-requests', [App\Http\Controllers\PublicSapRequestController::class, 'store'])->name('public.sap-requests.store');
Route::get('/public/tickets/{ticket}/close', [App\Http\Controllers\PublicTicketController::class, 'close'])->name('public.tickets.close');
Route::get('/public/ticket-attachments/{attachment}/download', [App\Http\Controllers\PublicTicketController::class, 'downloadAttachment'])->name('public.tickets.attachments.download');
Route::get('/public/survey/{token}', [App\Http\Controllers\PublicTicketController::class, 'showSurvey'])->name('public.survey');
Route::post('/public/survey/{token}', [App\Http\Controllers\PublicTicketController::class, 'submitSurvey'])->name('public.survey.submit');
Route::get('/public/survey-thank-you', function () {
    return Inertia::render('Public/SurveyThankYou');
})->name('public.survey.thankyou');

require __DIR__.'/auth.php';
