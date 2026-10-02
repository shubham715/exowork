<?php

use App\Http\Controllers\Api\CandidateRegistrationController;
use App\Http\Controllers\Api\CandidateDraftController;
use App\Http\Controllers\Api\MasterDataController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'application' => config('app.name'),
    ]);
});

Route::get('/master-data', [MasterDataController::class, 'index']);
Route::get('/master-data/states/{slug}/districts', [MasterDataController::class, 'districts']);

Route::post('/candidates', [CandidateRegistrationController::class, 'store'])
    ->middleware('throttle:10,1');

Route::prefix('candidate-drafts')->middleware('throttle:30,1')->group(function () {
    Route::post('/', [CandidateDraftController::class, 'store']);
    Route::get('/{token}', [CandidateDraftController::class, 'show']);
    Route::delete('/{token}', [CandidateDraftController::class, 'destroy']);
});

Route::get('/candidate-invite', function (\Illuminate\Http\Request $request) {
    $center = \App\Services\CenterInvite::resolve((string) $request->query('token'));
    return response()->json(['center' => $center, 'batches' => \Illuminate\Support\Facades\DB::table('training_batches')->where('training_center_id', $center->id)->get(['id', 'code', 'ends_on'])]);
})->middleware('throttle:30,1');
