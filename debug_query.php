<?php
$user = App\Models\User::where('email', 'user@gmail.com')->first();
DB::enableQueryLog();

$query = App\Models\Ticket::query();

if ($user->hasRole('Admin')) {
    echo "User is Admin\n";
} else {
    echo "User is NOT Admin\n";
    $user->load('roles.companies');
    $allowedCompanyIds = collect();
    
    foreach ($user->roles as $role) {
        if ($role->companies) {
            $allowedCompanyIds = $allowedCompanyIds->merge($role->companies->pluck('id'));
        }
    }
    
    if ($user->company_id) {
        $allowedCompanyIds->push($user->company_id);
    }
    
    $uniqueIds = $allowedCompanyIds->unique()->values()->all();
    echo "Allowed IDs: " . implode(', ', $uniqueIds) . "\n";
    
    $query->whereIn('company_id', $uniqueIds);
}

$count = $query->count();
echo "Count: $count\n";

$log = DB::getQueryLog();
$lastQuery = end($log);
echo "SQL: " . $lastQuery['query'] . "\n";
echo "Bindings: " . implode(', ', $lastQuery['bindings']) . "\n";

