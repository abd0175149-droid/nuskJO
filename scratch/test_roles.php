<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$user = \App\Models\User::where('email', 'admin@nusk.jo')->first();
if (!$user) {
    $user = \App\Models\User::first();
}

$request = Illuminate\Http\Request::create('/roles', 'GET');
if ($user) {
    $app->make('auth')->guard()->login($user);
}

$response = $kernel->handle($request);
echo "Status: " . $response->getStatusCode() . "\n";
echo "Content: \n" . substr($response->getContent(), 0, 500) . "\n";
if ($response->getStatusCode() == 500) {
    echo "Exception: \n" . ($response->exception ?? 'None') . "\n";
}
