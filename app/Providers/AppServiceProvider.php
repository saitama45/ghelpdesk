<?php

namespace App\Providers;

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

        // Override config from database - skip if running in console to prevent CI issues
        if (!app()->runningInConsole()) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                    $settings = \App\Models\Setting::where('group', 'mail')->pluck('value', 'key');

                    if ($settings->isNotEmpty()) {
                        // Outgoing Mail (SMTP)
                        if ($settings->has('mail_mailer')) config(['mail.default' => $settings->get('mail_mailer')]);
                        if ($settings->has('mail_host')) config(['mail.mailers.smtp.host' => $settings->get('mail_host')]);
                        if ($settings->has('mail_port')) config(['mail.mailers.smtp.port' => $settings->get('mail_port')]);
                        if ($settings->has('mail_username')) config(['mail.mailers.smtp.username' => $settings->get('mail_username')]);
                        if ($settings->has('mail_password')) config(['mail.mailers.smtp.password' => $settings->get('mail_password')]);
                        if ($settings->has('mail_encryption')) config(['mail.mailers.smtp.encryption' => $settings->get('mail_encryption')]);
                        
                        // From Address
                        if ($settings->has('mail_from_address')) config(['mail.from.address' => $settings->get('mail_from_address')]);
                        if ($settings->has('mail_from_name')) config(['mail.from.name' => $settings->get('mail_from_name')]);

                        // IMAP (for fetching emails)
                        if ($settings->has('imap_host')) config(['imap.accounts.default.host' => $settings->get('imap_host')]);
                        if ($settings->has('imap_port')) config(['imap.accounts.default.port' => $settings->get('imap_port')]);
                        if ($settings->has('imap_encryption')) config(['imap.accounts.default.encryption' => $settings->get('imap_encryption')]);
                        if ($settings->has('imap_username')) config(['imap.accounts.default.username' => $settings->get('imap_username')]);
                        if ($settings->has('imap_password')) config(['imap.accounts.default.password' => $settings->get('imap_password')]);
                    }
                }
            } catch (\Exception $e) {
                // Fail silently if DB is not ready or settings table doesn't exist yet
            }
        }
    }
}
