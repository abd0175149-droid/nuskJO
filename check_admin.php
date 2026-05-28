<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::where('email', 'admin@nusuk-jo.com')->first();
if ($user) {
    echo "User found: " . $user->name . " | Role ID: " . $user->role_id . "\n";
    if ($user->role) {
        echo "Role Slug: " . $user->role->slug . "\n";
        echo "isAdmin: " . ($user->isAdmin() ? 'true' : 'false') . "\n";
        echo "Permissions Count: " . $user->role->permissions()->count() . "\n";
    } else {
        echo "Role is NULL\n";
    }
} else {
    echo "User not found\n";
}
