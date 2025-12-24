<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CompanyController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

Route::get('/debug-roles', function () {
    return Role::all();
});

Route::get('/debug-storage', function () {
    $disk = Illuminate\Support\Facades\Storage::disk('public');
    $path = 'ticket-attachments';
    $files = $disk->files($path);
    
    $debug = [
        'disk_root' => config('filesystems.disks.public.root'),
        'disk_url' => config('filesystems.disks.public.url'),
        'public_path' => public_path(),
        'storage_path' => storage_path(),
        'symlink_target' => public_path('storage'),
        'symlink_exists' => is_link(public_path('storage')),
        'symlink_target_read' => is_link(public_path('storage')) ? readlink(public_path('storage')) : 'N/A',
        'directory_exists' => $disk->exists($path),
        'directory_writable' => is_writable($disk->path($path)),
        'file_count' => count($files),
        'latest_files' => array_slice($files, 0, 5),
    ];

    return $debug;
});

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::resource('users', UserController::class);
    Route::put('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    
    Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit']);
    Route::resource('companies', CompanyController::class)->except(['show', 'create', 'edit']);
    Route::resource('tickets', \App\Http\Controllers\TicketController::class);
    Route::post('tickets/{ticket}/comments', [\App\Http\Controllers\TicketController::class, 'storeComment'])->name('tickets.comments.store');
    Route::post('tickets/{ticket}/attachments', [\App\Http\Controllers\TicketController::class, 'storeAttachment'])->name('tickets.attachments.store');
    Route::get('attachments/{attachment}/download', [\App\Http\Controllers\TicketController::class, 'downloadAttachment'])->name('tickets.attachments.download');
    
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
});

require __DIR__.'/auth.php';