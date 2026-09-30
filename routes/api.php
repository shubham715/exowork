<?php

use App\Http\Controllers\Api\CandidateRegistrationController;
use App\Http\Controllers\Api\CandidateDraftController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'application' => config('app.name'),
    ]);
});

Route::post('/candidates', [CandidateRegistrationController::class, 'store'])
    ->middleware('throttle:10,1');

Route::prefix('candidate-drafts')->middleware('throttle:30,1')->group(function () {
    Route::post('/', [CandidateDraftController::class, 'store']);
    Route::get('/{token}', [CandidateDraftController::class, 'show']);
    Route::delete('/{token}', [CandidateDraftController::class, 'destroy']);
});
