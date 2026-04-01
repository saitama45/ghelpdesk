<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CompanyController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

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
    Route::get('categories/template', [\App\Http\Controllers\CategoryController::class, 'template'])->name('categories.template');
    Route::post('categories/import', [\App\Http\Controllers\CategoryController::class, 'import'])->name('categories.import');
    Route::get('sub-categories/template', [\App\Http\Controllers\SubCategoryController::class, 'template'])->name('sub-categories.template');
    Route::post('sub-categories/import', [\App\Http\Controllers\SubCategoryController::class, 'import'])->name('sub-categories.import');
    Route::get('items/template', [\App\Http\Controllers\ItemController::class, 'template'])->name('items.template');
    Route::post('items/import', [\App\Http\Controllers\ItemController::class, 'import'])->name('items.import');
    Route::resource('categories', \App\Http\Controllers\CategoryController::class)->except(['show', 'create', 'edit']);
    Route::resource('sub-categories', \App\Http\Controllers\SubCategoryController::class)->except(['show', 'create', 'edit']);
    Route::resource('items', \App\Http\Controllers\ItemController::class)->except(['show', 'create', 'edit']);
    Route::resource('request-types', \App\Http\Controllers\RequestTypeController::class)->except(['show', 'create', 'edit']);
    Route::resource('pos-requests', \App\Http\Controllers\PosRequestController::class);
    Route::post('pos-requests/{pos_request}/approve', [\App\Http\Controllers\PosRequestController::class, 'approve'])->name('pos-requests.approve');
    Route::get('stores/template', [\App\Http\Controllers\StoreController::class, 'template'])->name('stores.template');
    Route::post('stores/import', [\App\Http\Controllers\StoreController::class, 'import'])->name('stores.import');
    Route::resource('stores', \App\Http\Controllers\StoreController::class)->except(['show', 'create', 'edit']);
    Route::resource('activity-templates', \App\Http\Controllers\ActivityTemplateController::class)->except(['show', 'create', 'edit']);
    Route::get('schedules/template', [\App\Http\Controllers\ScheduleController::class, 'template'])->name('schedules.template');
    Route::post('schedules/import', [\App\Http\Controllers\ScheduleController::class, 'import'])->name('schedules.import');
    Route::resource('schedules', \App\Http\Controllers\ScheduleController::class)->except(['show', 'create', 'edit']);
    Route::get('schedules/export/pdf', [\App\Http\Controllers\ScheduleExportController::class, 'pdf'])->name('schedules.export.pdf');
    Route::get('tickets/data/categories', [\App\Http\Controllers\TicketController::class, 'getCategories'])->name('tickets.data.categories');
    Route::get('tickets/data/subcategories', [\App\Http\Controllers\TicketController::class, 'getSubCategories'])->name('tickets.data.subcategories');
    Route::get('tickets/data/items', [\App\Http\Controllers\TicketController::class, 'getItems'])->name('tickets.data.items');
    Route::post('tickets/sync', [\App\Http\Controllers\TicketController::class, 'sync'])->name('tickets.sync');

    Route::post('tickets/bulk-update', [\App\Http\Controllers\TicketController::class, 'bulkUpdate'])->name('tickets.bulk-update');
    Route::resource('tickets', \App\Http\Controllers\TicketController::class);
    Route::post('tickets/{ticket}/child', [\App\Http\Controllers\TicketController::class, 'storeChild'])->name('tickets.store-child');
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

    // NSO Project Tracker
    Route::resource('projects', \App\Http\Controllers\ProjectController::class);
    Route::post('projects/{project}/apply-templates', [\App\Http\Controllers\ProjectTaskController::class, 'applyTemplates'])->name('projects.apply-templates');
    Route::post('projects/tasks/gantt', [\App\Http\Controllers\ProjectTaskController::class, 'updateGantt'])->name('projects.tasks.gantt-update');
    Route::resource('projects-tasks', \App\Http\Controllers\ProjectTaskController::class)->only(['store', 'update', 'destroy']);
    Route::resource('projects-assets', \App\Http\Controllers\ProjectAssetController::class)->only(['store', 'update', 'destroy']);
    Route::resource('projects-team-members', \App\Http\Controllers\ProjectTeamMemberController::class)->only(['store', 'destroy']);
});

// Public Routes (No Auth)
Route::get('/public/pos-requests/create', [App\Http\Controllers\PublicPosRequestController::class, 'create'])->name('public.pos-requests.create');
Route::post('/public/pos-requests', [App\Http\Controllers\PublicPosRequestController::class, 'store'])->name('public.pos-requests.store');
Route::get('/public/tickets/{ticket}/close', [App\Http\Controllers\PublicTicketController::class, 'close'])->name('public.tickets.close');
Route::get('/public/survey/{token}', [App\Http\Controllers\PublicTicketController::class, 'showSurvey'])->name('public.survey');
Route::post('/public/survey/{token}', [App\Http\Controllers\PublicTicketController::class, 'submitSurvey'])->name('public.survey.submit');
Route::get('/public/survey-thank-you', function () {
    return Inertia::render('Public/SurveyThankYou');
})->name('public.survey.thankyou');

require __DIR__.'/auth.php';