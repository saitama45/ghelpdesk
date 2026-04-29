<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CompanyController;
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
    return redirect()->route('login');
});

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
    Route::resource('users', UserController::class);
    Route::put('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    
    Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit']);
    Route::resource('companies', CompanyController::class)->except(['show', 'create', 'edit']);
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
    Route::get('forms/{slug}', [\App\Http\Controllers\DynamicFormController::class, 'index'])->name('dynamic-form.index');
    Route::post('forms/{slug}', [\App\Http\Controllers\DynamicFormController::class, 'store'])->name('dynamic-form.store');
    Route::get('forms/{slug}/{id}', [\App\Http\Controllers\DynamicFormController::class, 'show'])->name('dynamic-form.show');
    Route::put('forms/{slug}/{id}', [\App\Http\Controllers\DynamicFormController::class, 'update'])->name('dynamic-form.update');
    Route::delete('forms/{slug}/{id}', [\App\Http\Controllers\DynamicFormController::class, 'destroy'])->name('dynamic-form.destroy');
    Route::post('forms/{slug}/{id}/approve', [\App\Http\Controllers\DynamicFormController::class, 'approve'])->name('dynamic-form.approve');
    Route::resource('pos-requests', \App\Http\Controllers\PosRequestController::class);
    Route::post('pos-requests/{pos_request}/approve', [\App\Http\Controllers\PosRequestController::class, 'approve'])->name('pos-requests.approve');
    Route::post('pos-requests/{pos_request}/reject', [\App\Http\Controllers\PosRequestController::class, 'reject'])->name('pos-requests.reject');
    Route::resource('sap-requests', \App\Http\Controllers\SapRequestController::class);
    Route::post('sap-requests/{sap_request}/approve', [\App\Http\Controllers\SapRequestController::class, 'approve'])->name('sap-requests.approve');
    Route::post('sap-requests/{sap_request}/reject', [\App\Http\Controllers\SapRequestController::class, 'reject'])->name('sap-requests.reject');
    Route::post('stock-ins/{stock_in}/post', [\App\Http\Controllers\StockInController::class, 'post'])->name('stock-ins.post');
    Route::get('stock-ins/{stock_in}/print-barcodes', [\App\Http\Controllers\StockInController::class, 'printBarcodes'])->name('stock-ins.print-barcodes');
    Route::get('stock-ins/{stock_in}/print-qrcodes', [\App\Http\Controllers\StockInController::class, 'printQrcodes'])->name('stock-ins.print-qrcodes');
    Route::resource('stock-ins', \App\Http\Controllers\StockInController::class);
    Route::get('stores/template', [\App\Http\Controllers\StoreController::class, 'template'])->name('stores.template');
    Route::post('stores/import', [\App\Http\Controllers\StoreController::class, 'import'])->name('stores.import');
    Route::resource('stores', \App\Http\Controllers\StoreController::class)->except(['show', 'create', 'edit']);
    Route::resource('vendors', \App\Http\Controllers\VendorController::class)->except(['show', 'create', 'edit']);
    Route::resource('activity-templates', \App\Http\Controllers\ActivityTemplateController::class)->except(['show', 'create', 'edit']);
    Route::get('schedules/template', [\App\Http\Controllers\ScheduleController::class, 'template'])->name('schedules.template');
    Route::post('schedules/import', [\App\Http\Controllers\ScheduleController::class, 'import'])->name('schedules.import');
    Route::get('schedules/report-data', [\App\Http\Controllers\ScheduleController::class, 'reportData'])->name('schedules.report-data');
    Route::get('schedules/missing-schedules', [\App\Http\Controllers\ScheduleController::class, 'missingSchedules'])->name('schedules.missing-schedules');
    Route::resource('schedules', \App\Http\Controllers\ScheduleController::class)->except(['show', 'create', 'edit']);
    Route::get('schedules/export/pdf', [\App\Http\Controllers\ScheduleExportController::class, 'pdf'])->name('schedules.export.pdf');
    Route::get('tickets/data/categories', [\App\Http\Controllers\TicketController::class, 'getCategories'])->name('tickets.data.categories');
    Route::get('tickets/data/subcategories', [\App\Http\Controllers\TicketController::class, 'getSubCategories'])->name('tickets.data.subcategories');
    Route::get('tickets/data/items', [\App\Http\Controllers\TicketController::class, 'getItems'])->name('tickets.data.items');
    Route::get('assets/template', [\App\Http\Controllers\AssetController::class, 'template'])->name('assets.template');
    Route::post('assets/import', [\App\Http\Controllers\AssetController::class, 'import'])->name('assets.import');
    Route::get('assets/generate-code', [\App\Http\Controllers\AssetController::class, 'generateCode'])->name('assets.generate-code');
    Route::resource('assets', \App\Http\Controllers\AssetController::class)->except(['show', 'create', 'edit']);
    Route::post('tickets/sync', [\App\Http\Controllers\TicketController::class, 'sync'])->name('tickets.sync');

    Route::post('tickets/bulk-update', [\App\Http\Controllers\TicketController::class, 'bulkUpdate'])->name('tickets.bulk-update');
    Route::post('tickets/bulk-child', [\App\Http\Controllers\TicketController::class, 'bulkStoreChild'])->name('tickets.bulk-child');
    Route::post('tickets/merge', [\App\Http\Controllers\TicketController::class, 'merge'])->name('tickets.merge');
    Route::resource('tickets', \App\Http\Controllers\TicketController::class);
    Route::post('tickets/{ticket}/split', [\App\Http\Controllers\TicketController::class, 'split'])->name('tickets.split');
    Route::post('tickets/{ticket}/child', [\App\Http\Controllers\TicketController::class, 'storeChild'])->name('tickets.store-child');
    Route::post('tickets/{ticket}/duplicate', [\App\Http\Controllers\TicketController::class, 'duplicate'])->name('tickets.duplicate');
    Route::post('tickets/{ticket}/comments', [\App\Http\Controllers\TicketController::class, 'storeComment'])->name('tickets.comments.store');
    Route::post('tickets/{ticket}/attachments', [\App\Http\Controllers\TicketController::class, 'storeAttachment'])->name('tickets.attachments.store');
    Route::get('attachments/{attachment}/download', [\App\Http\Controllers\TicketController::class, 'downloadAttachment'])->name('tickets.attachments.download');
    
    Route::get('settings', [\App\Http\Controllers\SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/test-imap', [\App\Http\Controllers\SettingsController::class, 'testImap'])->name('settings.test-imap');
    Route::resource('canned-messages', \App\Http\Controllers\CannedMessageController::class)->except(['show', 'create', 'edit']);

    Route::get('reports/store-health', [\App\Http\Controllers\StoreReportController::class, 'index'])->name('reports.store-health');
    Route::get('reports/store-health/pdf', [\App\Http\Controllers\StoreReportController::class, 'pdf'])->name('reports.store-health.pdf');
    Route::get('reports/store-health/{store}/tickets', [\App\Http\Controllers\StoreReportController::class, 'getTickets'])->name('reports.store-health.tickets');
    Route::get('reports/sla-performance', [\App\Http\Controllers\SlaReportController::class, 'index'])->name('reports.sla-performance');
    Route::get('reports/sla-performance/pdf', [\App\Http\Controllers\SlaReportController::class, 'pdf'])->name('reports.sla-performance.pdf');
    Route::get('reports/sla-performance/tickets', [\App\Http\Controllers\SlaReportController::class, 'getTickets'])->name('reports.sla-performance.tickets');
    Route::get('reports/assignee-performance', [\App\Http\Controllers\AssigneePerformanceReportController::class, 'index'])->name('reports.assignee-performance');
    Route::get('reports/assignee-performance/pdf', [\App\Http\Controllers\AssigneePerformanceReportController::class, 'pdf'])->name('reports.assignee-performance.pdf');

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

    // NSO Project Tracker
    Route::resource('projects', \App\Http\Controllers\ProjectController::class);
    Route::post('projects/{project}/apply-templates', [\App\Http\Controllers\ProjectTaskController::class, 'applyTemplates'])->name('projects.apply-templates');
    Route::post('projects/tasks/gantt', [\App\Http\Controllers\ProjectTaskController::class, 'updateGantt'])->name('projects.tasks.gantt-update');
    Route::resource('projects-tasks', \App\Http\Controllers\ProjectTaskController::class)->only(['store', 'update', 'destroy']);
    Route::resource('projects-assets', \App\Http\Controllers\ProjectAssetController::class)->only(['store', 'update', 'destroy']);
    Route::resource('projects-team-members', \App\Http\Controllers\ProjectTeamMemberController::class)->only(['store', 'destroy']);
});

// Public Routes (No Auth)
Route::get('/attachments/download', [App\Http\Controllers\AttachmentController::class, 'download'])->name('attachments.download');
Route::get('/public/pos-requests/create', [App\Http\Controllers\PublicPosRequestController::class, 'create'])->name('public.pos-requests.create');
Route::post('/public/pos-requests', [App\Http\Controllers\PublicPosRequestController::class, 'store'])->name('public.pos-requests.store');
Route::get('/public/sap-requests/create', [App\Http\Controllers\PublicSapRequestController::class, 'create'])->name('public.sap-requests.create');
Route::post('/public/sap-requests', [App\Http\Controllers\PublicSapRequestController::class, 'store'])->name('public.sap-requests.store');
Route::get('/public/tickets/{ticket}/close', [App\Http\Controllers\PublicTicketController::class, 'close'])->name('public.tickets.close');
Route::get('/public/survey/{token}', [App\Http\Controllers\PublicTicketController::class, 'showSurvey'])->name('public.survey');
Route::post('/public/survey/{token}', [App\Http\Controllers\PublicTicketController::class, 'submitSurvey'])->name('public.survey.submit');
Route::get('/public/survey-thank-you', function () {
    return Inertia::render('Public/SurveyThankYou');
})->name('public.survey.thankyou');

require __DIR__.'/auth.php';
