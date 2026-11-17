<?php

use App\Http\Controllers\Agent\AgentApiController;
use App\Http\Middleware\AuthenticateAgentToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent API (ADR 086) -- /api/agent/v1/*
|--------------------------------------------------------------------------
|
| Read-only JSON for the AI Workforce (TICorp). Authenticated by a Sanctum
| personal access token minted in Master Admin > Akses Agent; every route
| names the ONE scope it needs. Nothing here writes. A capability the agents
| need to CHANGE something is a separate, deliberately designed endpoint,
| never a widening of this group.
*/
Route::prefix('agent/v1')->name('agent.')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    $scope = fn (string $s) => AuthenticateAgentToken::class.':'.$s;

    Route::get('/ringkasan', [AgentApiController::class, 'summary'])->middleware($scope('ops'))->name('summary');
    Route::get('/paket', [AgentApiController::class, 'plans'])->middleware($scope('ops'))->name('plans');

    Route::get('/tenant', [AgentApiController::class, 'tenants'])->middleware($scope('tenants'))->name('tenants');
    Route::get('/tenant/{tenant}', [AgentApiController::class, 'tenant'])->middleware($scope('tenants'))->name('tenant');

    Route::get('/pembayaran', [AgentApiController::class, 'payments'])->middleware($scope('billing'))->name('payments');

    Route::get('/tiket', [AgentApiController::class, 'tickets'])->middleware($scope('support'))->name('tickets');
    Route::get('/tiket/{ticket}', [AgentApiController::class, 'ticket'])->middleware($scope('support'))->name('ticket');

    Route::get('/pendaftaran', [AgentApiController::class, 'registrations'])->middleware($scope('growth'))->name('registrations');
});
