<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\CenterCandidateController;

Route::post('/auth/login', [AuthSessionController::class, 'store'])->middleware('throttle:10,1');
Route::post('/auth/logout', [AuthSessionController::class, 'destroy']);
Route::middleware('auth:web')->group(function () {
    Route::get('/center-api/candidate-context', [CenterCandidateController::class, 'context']);
    Route::post('/center-api/candidates', [CenterCandidateController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| React application
|--------------------------------------------------------------------------
|
| React Router owns all browser-facing routes. Laravel API and webhook
| endpoints are registered separately and are deliberately excluded here.
|
*/
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api(?:/|$)|webhooks(?:/|$)).*');
