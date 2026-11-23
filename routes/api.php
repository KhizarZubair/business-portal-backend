<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LinkedInOAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->middleware('throttle:auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::get('/integrations/linkedin/callback', [LinkedInOAuthController::class, 'callback'])
        ->name('api.v1.linkedin.callback');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/dashboard', DashboardController::class);

        Route::get('/integrations/linkedin', [LinkedInOAuthController::class, 'show']);
        Route::get('/integrations/linkedin/redirect', [LinkedInOAuthController::class, 'redirect']);
        Route::delete('/integrations/linkedin', [LinkedInOAuthController::class, 'destroy']);
    });
});
