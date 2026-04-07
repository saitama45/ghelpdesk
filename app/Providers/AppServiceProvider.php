<?php

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Force HTTPS if we are in production (Azure)
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        // Implicitly grant "Admin", "Dev", and "Solutions Admin" roles all permissions
        Gate::before(function ($user, $ability) {
            return $user->hasAnyRole(['Admin', 'Dev', 'Solutions Admin']) ? true : null;
        });

        \App\Models\Ticket::observe(\App\Observers\TicketObserver::class);
        \App\Models\ProjectTask::observe(\App\Observers\ProjectTaskObserver::class);

        // Override config from database — cached for 1 hour so this never hits the DB per-request.
        // Clear cache via: Cache::forget('app_mail_settings') after saving settings in the UI.
        if (config('app.env') !== 'testing') {
            try {
                $settings = Cache::remember('app_mail_settings', 3600, function () {
                    \Illuminate\Support\Facades\DB::connection()->getPdo();
                    if (!\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                        return collect();
                    }
                    return \App\Models\Setting::where('group', 'mail')->pluck('value', 'key');
                });

                if ($settings->isNotEmpty()) {
                    if ($settings->has('mail_mailer')) config(['mail.default' => $settings->get('mail_mailer')]);
                    if ($settings->has('mail_host')) config(['mail.mailers.smtp.host' => $settings->get('mail_host')]);
                    if ($settings->has('mail_port')) config(['mail.mailers.smtp.port' => $settings->get('mail_port')]);
                    if ($settings->has('mail_username')) config(['mail.mailers.smtp.username' => $settings->get('mail_username')]);
                    if ($settings->has('mail_password')) config(['mail.mailers.smtp.password' => $settings->get('mail_password')]);
                    if ($settings->has('mail_encryption')) config(['mail.mailers.smtp.encryption' => $settings->get('mail_encryption')]);
                    if ($settings->has('mail_from_address')) config(['mail.from.address' => $settings->get('mail_from_address')]);
                    if ($settings->has('mail_from_name')) config(['mail.from.name' => $settings->get('mail_from_name')]);
                    if ($settings->has('imap_host')) config(['imap.accounts.default.host' => $settings->get('imap_host')]);
                    if ($settings->has('imap_port')) config(['imap.accounts.default.port' => $settings->get('imap_port')]);
                    if ($settings->has('imap_encryption')) config(['imap.accounts.default.encryption' => $settings->get('imap_encryption')]);
                    if ($settings->has('imap_username')) config(['imap.accounts.default.username' => $settings->get('imap_username')]);
                    if ($settings->has('imap_password')) config(['imap.accounts.default.password' => $settings->get('imap_password')]);
                }
            } catch (\Throwable $e) {
                if (!app()->runningInConsole()) {
                    \Illuminate\Support\Facades\Log::warning("AppServiceProvider: Could not load database settings. Using environment defaults. Error: " . $e->getMessage());
                }
            }
        }

    }
}
