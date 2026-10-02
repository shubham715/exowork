<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\CenterCandidateController;
use App\Http\Controllers\AdminMasterDataController;
use App\Http\Controllers\CandidateSessionController;
use App\Http\Controllers\CandidateProfileController;
use App\Http\Controllers\AdminTrainingCenterController;
use App\Http\Controllers\TrainingCenterRegistrationController;
use App\Http\Controllers\CenterDashboardController;

Route::get('/auth/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token()])
        ->header('Cache-Control', 'no-store, private');
});

Route::view('/reset-password/{token}', 'app')->name('password.reset');
Route::post('/auth/employer/password/email', [\App\Http\Controllers\EmployerPasswordController::class, 'email'])->middleware('throttle:5,1');
Route::post('/auth/employer/password/reset', [\App\Http\Controllers\EmployerPasswordController::class, 'reset'])->middleware('throttle:5,1');
Route::post('/auth/register/employer', [\App\Http\Controllers\EmployerWorkspaceController::class, 'register'])->middleware('throttle:5,1');

Route::post('/auth/login', [AuthSessionController::class, 'store'])->middleware('throttle:10,1');
Route::post('/auth/register/training-center', [TrainingCenterRegistrationController::class, 'store'])->middleware('throttle:5,1');
Route::post('/auth/logout', [AuthSessionController::class, 'destroy']);
Route::middleware('auth:web')->group(function () {
    Route::get('/admin-api/candidates', [\App\Http\Controllers\AdminCandidateController::class, 'index']);
    Route::get('/admin-api/candidates/export', [\App\Http\Controllers\AdminCandidateController::class, 'export']);
    Route::post('/admin-api/candidates/actions', [\App\Http\Controllers\AdminCandidateController::class, 'actions']);
    Route::patch('/admin-api/candidates/{code}', [\App\Http\Controllers\AdminCandidateController::class, 'update']);
    Route::get('/admin-api/candidates/{code}', [\App\Http\Controllers\WhatsAppController::class, 'show']);
    foreach (['admin-api', 'employer-api'] as $prefix) {
        Route::get("/$prefix/candidates/{code}/whatsapp", [\App\Http\Controllers\WhatsAppController::class, 'context'])->middleware('throttle:30,1');
        Route::post("/$prefix/candidates/{code}/whatsapp", [\App\Http\Controllers\WhatsAppController::class, 'send'])->middleware('throttle:10,1');
    }
    Route::get('/admin-api/review-notifications', [\App\Http\Controllers\AdminReviewQueueController::class, 'index']);
    Route::post('/admin-api/review-notifications/read', [\App\Http\Controllers\AdminReviewQueueController::class, 'read']);
    Route::get('/employer-api/identity', [\App\Http\Controllers\EmployerWorkspaceController::class, 'identity']);
    Route::get('/employer-api/workspace', [\App\Http\Controllers\EmployerWorkspaceController::class, 'workspace']);
    Route::post('/employer-api/profile', [\App\Http\Controllers\EmployerWorkspaceController::class, 'updateProfile']);
    Route::get('/employer-api/document', [\App\Http\Controllers\EmployerWorkspaceController::class, 'document']);
    Route::post('/employer-api/jobs', [\App\Http\Controllers\EmployerWorkspaceController::class, 'saveJob']);
    Route::put('/employer-api/jobs/{id}', [\App\Http\Controllers\EmployerWorkspaceController::class, 'saveJob']);
    Route::get('/admin-api/employers', [\App\Http\Controllers\EmployerWorkspaceController::class, 'adminIndex']);
    Route::get('/admin-api/employers/stats', [\App\Http\Controllers\AdminEmployerAnalyticsController::class, 'stats']);
    Route::get('/admin-api/employers/export', [\App\Http\Controllers\AdminEmployerAnalyticsController::class, 'export']);
    Route::get('/admin-api/employers/{id}', [\App\Http\Controllers\AdminEmployerAnalyticsController::class, 'show'])->whereNumber('id');
    Route::post('/admin-api/employers/actions', [\App\Http\Controllers\AdminEmployerAnalyticsController::class, 'bulk']);
    Route::patch('/admin-api/employers/{id}', [\App\Http\Controllers\AdminEmployerAnalyticsController::class, 'update']);
    Route::get('/admin-api/employers/{id}/document', [\App\Http\Controllers\EmployerWorkspaceController::class, 'adminDocument']);
    Route::patch('/admin-api/employers/{id}/review', [\App\Http\Controllers\EmployerWorkspaceController::class, 'review']);

    Route::get('/center-api/workspace', [\App\Http\Controllers\CenterWorkspaceController::class, 'show']);
    Route::patch('/center-api/placements/{id}/joining', [\App\Http\Controllers\CenterWorkspaceController::class, 'joining']);
    Route::get('/center-api/identity', [CenterDashboardController::class, 'identity']);
    Route::get('/center-api/dashboard', [CenterDashboardController::class, 'show']);
    Route::get('/center-api/onboarding', [TrainingCenterRegistrationController::class, 'show']);
    Route::put('/center-api/onboarding', [TrainingCenterRegistrationController::class, 'update']);
    Route::get('/center-api/batches', [\App\Http\Controllers\CenterBatchController::class, 'batches']);
    Route::post('/center-api/batches', [\App\Http\Controllers\CenterBatchController::class, 'saveBatch']);
    Route::put('/center-api/batches/{id}', [\App\Http\Controllers\CenterBatchController::class, 'saveBatch']);
    Route::post('/center-api/batches/{id}/assign', [\App\Http\Controllers\CenterBatchController::class, 'assign']);
    Route::get('/center-api/candidate-context', [CenterCandidateController::class, 'context']);
    Route::post('/center-api/candidates', [CenterCandidateController::class, 'store']);
    Route::get('/center-api/candidates', [CenterCandidateController::class, 'index']);
    Route::get('/center-api/candidates/csv', [CenterCandidateController::class, 'csv']);
    Route::post('/center-api/candidates/import/preview', [CenterCandidateController::class, 'previewImport']);
    Route::post('/center-api/candidates/import/confirm', [CenterCandidateController::class, 'confirmImport']);
    Route::get('/center-api/candidates/invite', [CenterCandidateController::class, 'invite']);
    Route::post('/center-api/candidates/invite', [CenterCandidateController::class, 'sendInvite'])->middleware('throttle:10,1');
    Route::match(['get', 'patch'], '/center-api/candidates/{code}/edit', [CenterCandidateController::class, 'edit']);
    Route::get('/admin-api/master-data', [AdminMasterDataController::class, 'index']);
    Route::post('/admin-api/master-data/{type}', [AdminMasterDataController::class, 'store']);
    Route::patch('/admin-api/master-data/{type}/{id}', [AdminMasterDataController::class, 'update']);
    Route::get('/admin-api/training-centers', [AdminTrainingCenterController::class, 'index']);
    Route::post('/admin-api/training-partners', [AdminTrainingCenterController::class, 'storePartner']);
    Route::post('/admin-api/training-centers', [AdminTrainingCenterController::class, 'store']);
    Route::put('/admin-api/training-centers/{id}', [AdminTrainingCenterController::class, 'update']);
    Route::patch('/admin-api/training-centers/{id}/review', [AdminTrainingCenterController::class, 'review']);
});
Route::middleware('auth:candidate')->group(function () {
    Route::get('/candidate-api/profile', [CandidateSessionController::class, 'show']);
    Route::get('/candidate-api/profile/details', [CandidateProfileController::class, 'show']);
    Route::patch('/candidate-api/profile', [CandidateProfileController::class, 'update']);
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
