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
    Route::get('/dtr', [\App\Http\Controllers\AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/logs', [\App\Http\Controllers\AttendanceController::class, 'logs'])->name('attendance.logs');
    Route::post('/attendance/log', [\App\Http\Controllers\AttendanceController::class, 'log'])->name('attendance.log');
    Route::resource('users', UserController::class);
    Route::put('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    
    Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit']);
    Route::resource('companies', CompanyController::class)->except(['show', 'create', 'edit']);
    Route::resource('categories', \App\Http\Controllers\CategoryController::class)->except(['show', 'create', 'edit']);
    Route::resource('sub-categories', \App\Http\Controllers\SubCategoryController::class)->except(['show', 'create', 'edit']);
    Route::resource('items', \App\Http\Controllers\ItemController::class)->except(['show', 'create', 'edit']);
    Route::resource('stores', \App\Http\Controllers\StoreController::class)->except(['show', 'create', 'edit']);
    Route::resource('schedules', \App\Http\Controllers\ScheduleController::class)->except(['show', 'create', 'edit']);
    Route::get('schedules/export/pdf', [\App\Http\Controllers\ScheduleExportController::class, 'pdf'])->name('schedules.export.pdf');
    Route::get('tickets/data/categories', [\App\Http\Controllers\TicketController::class, 'getCategories'])->name('tickets.data.categories');
    Route::get('tickets/data/subcategories', [\App\Http\Controllers\TicketController::class, 'getSubCategories'])->name('tickets.data.subcategories');
    Route::get('tickets/data/items', [\App\Http\Controllers\TicketController::class, 'getItems'])->name('tickets.data.items');
    Route::post('tickets/sync', [\App\Http\Controllers\TicketController::class, 'sync'])->name('tickets.sync');

    Route::resource('tickets', \App\Http\Controllers\TicketController::class);
    Route::post('tickets/{ticket}/child', [\App\Http\Controllers\TicketController::class, 'storeChild'])->name('tickets.store-child');
    Route::post('tickets/{ticket}/comments', [\App\Http\Controllers\TicketController::class, 'storeComment'])->name('tickets.comments.store');
    Route::post('tickets/{ticket}/attachments', [\App\Http\Controllers\TicketController::class, 'storeAttachment'])->name('tickets.attachments.store');
    Route::get('attachments/{attachment}/download', [\App\Http\Controllers\TicketController::class, 'downloadAttachment'])->name('tickets.attachments.download');
    
    Route::get('settings', [\App\Http\Controllers\SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
});

require __DIR__.'/auth.php';