<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class IssueIntegrationToken extends Command
{
    protected $signature = 'integration:issue-token {system=linkportal : Consuming system name}';

    protected $description = 'Create (or reuse) a service user and issue a Sanctum token for app-to-app calls into ghelpdesk';

    public function handle(): int
    {
        $system = strtolower($this->argument('system'));
        $email = "integration+{$system}@ghelpdesk.local";

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Integration: '.ucfirst($system),
                'password' => Str::random(64), // never used for login
                'is_active' => true,
            ],
        );

        // The linkportal service account only needs to file document reviews
        foreach (['accounting-documents.view'] as $permission) {
            if (Permission::where('name', $permission)->exists()) {
                $user->givePermissionTo($permission);
            }
        }

        $user->tokens()->where('name', "{$system}-integration")->delete();
        $token = $user->createToken("{$system}-integration");

        $this->info("Service user: {$email} (id {$user->id})");
        $this->newLine();
        $this->line('Plaintext token (shown once — put it in the other app\'s .env):');
        $this->warn($token->plainTextToken);

        return self::SUCCESS;
    }
}
