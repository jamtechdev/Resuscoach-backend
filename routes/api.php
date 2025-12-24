<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExamController;
use Illuminate\Support\Facades\Route;

// API v1 routes
Route::prefix('v1')->group(function () {
    // Public authentication routes
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/password/email', [AuthController::class, 'sendPasswordResetLink']);
    Route::post('/password/reset', [AuthController::class, 'resetPassword']);

    // Email verification route (public, but requires signed URL)
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed'])
        ->name('verification.verify');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        // Authentication routes
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/email/verify/resend', [AuthController::class, 'resendVerificationEmail']);

        // Development only: Manual email verification (for testing)
        if (!app()->environment('production')) {
            Route::post('/email/verify/{id}/manual', [AuthController::class, 'manualVerifyEmail']);
            Route::get('/password/reset-token/{email}', [AuthController::class, 'getPasswordResetToken']);
        }

        // Exam routes
        Route::prefix('exams')->name('api.exams.')->group(function () {
            Route::post('/start', [ExamController::class, 'start'])->name('start');
            Route::get('/{id}', [ExamController::class, 'show'])->name('show');
            Route::post('/{id}/answer', [ExamController::class, 'submitAnswer'])->name('answer');
            Route::post('/{id}/flag', [ExamController::class, 'flagQuestion'])->name('flag');
            Route::post('/{id}/submit', [ExamController::class, 'submit'])->name('submit');
            Route::get('/{id}/results', [ExamController::class, 'results'])->name('results');
        });
    });
});
