<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class UpdateStalePresence extends Command
{
    protected $signature = 'presence:update-stale';
    protected $description = 'Mark users as offline if they have been inactive for more than 10 minutes';

    public function handle()
    {
        $staleLimit = now()->subMinutes(10);

        $staleUsers = User::where('status', '!=', 'offline')
            ->where(function($query) use ($staleLimit) {
                $query->where('last_activity_at', '<', $staleLimit)
                      ->orWhereNull('last_activity_at');
            })
            ->get();

        foreach ($staleUsers as $user) {
            $user->updateStatus('offline');
            $this->info("Marked user {$user->name} as offline.");
        }

        return Command::SUCCESS;
    }
}