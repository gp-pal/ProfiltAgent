<?php

use App\Http\Controllers\RunArtifactController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Secure run-artifact streaming for the admin panel. The `web` group (applied
// to this file by RouteServiceProvider) supplies session + route-model binding;
// Filament's Authenticate guard redirects unauthenticated users to the panel
// login. The client never supplies a filesystem path — only the run + basename.
Route::get('/admin/runs/{run}/screenshot/{name}', [RunArtifactController::class, 'screenshot'])
    ->middleware(Authenticate::class)
    ->name('admin.runs.screenshot');

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'ProfiltAgent Orchestrator',
        'timestamp' => now()->toISOString()
    ]);
});