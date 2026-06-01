<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Boot facades
$app->make('db'); // forces database boot

$request = Illuminate\Http\Request::create('/roles', 'GET');
$user = \App\Models\User::where('email', 'admin@nusk.jo')->first() ?? \App\Models\User::first();
if ($user) {
    $app->make('auth')->guard()->login($user);
}

$response = $kernel->handle($request);
echo "Status: " . $response->getStatusCode() . "\n";
if ($response->getStatusCode() >= 400) {
    echo $response->exception ?? $response->getContent();
}
