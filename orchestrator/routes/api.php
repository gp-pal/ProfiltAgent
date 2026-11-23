<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RunController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| ProfiltAgent Core API Routes
|--------------------------------------------------------------------------
|
| Core orchestrator endpoints for Step-1 execution
|
*/

// Step-1 execution endpoint
Route::post('/run', [RunController::class, 'run'])->middleware(['api.key', 'flush.langfuse'])->name('api.run');

Route::prefix('v1/agent')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'services' => [
                'react_loop' => 'available',
                'browser_runner' => config('services.browser_runner.url', 'http://localhost:8080')
            ],
            'version' => '0.1.0'
        ]);
    })->name('api.agent.health');
});