<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoachingController;
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

    // Public exam routes (metadata only, no sensitive data)
    Route::prefix('exams')->name('api.exams.')->group(function () {
        Route::get('/topics', [ExamController::class, 'getTopics'])->name('topics');
    });

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

        // Protected exam routes
        Route::prefix('exams')->name('api.exams.')->group(function () {
            Route::get('/history', [ExamController::class, 'history'])->name('history');
            Route::get('/statistics', [ExamController::class, 'statistics'])->name('statistics');
            Route::get('/check-in-progress', [ExamController::class, 'checkInProgress'])->name('check-in-progress');
            Route::post('/start', [ExamController::class, 'start'])->name('start');
            Route::get('/{id}', [ExamController::class, 'show'])->name('show');
            Route::get('/{id}/flagged', [ExamController::class, 'getFlaggedQuestions'])->name('flagged');
            Route::post('/{id}/answer', [ExamController::class, 'submitAnswer'])->name('answer');
            Route::post('/{id}/flag', [ExamController::class, 'flagQuestion'])->name('flag');
            Route::post('/{id}/submit', [ExamController::class, 'submit'])->name('submit');
            Route::get('/{id}/results', [ExamController::class, 'results'])->name('results');
        });

        // Protected coaching routes
        Route::prefix('coaching')->name('api.coaching.')->group(function () {
            Route::post('/start/{examId}', [CoachingController::class, 'start'])->name('start');
            Route::get('/{sessionId}', [CoachingController::class, 'show'])->name('show');
            Route::get('/{sessionId}/questions', [CoachingController::class, 'getAllQuestions'])->name('get-all-questions');
            Route::get('/{sessionId}/question/{questionId}/step', [CoachingController::class, 'getCurrentStep'])->name('get-current-step');
            Route::post('/{sessionId}/respond', [CoachingController::class, 'respond'])->name('respond');
            Route::post('/{sessionId}/current-question', [CoachingController::class, 'updateCurrentQuestion'])->name('update-current-question');
            Route::post('/{sessionId}/pause', [CoachingController::class, 'pause'])->name('pause');
            Route::post('/{sessionId}/resume', [CoachingController::class, 'resume'])->name('resume');
            Route::post('/{sessionId}/complete', [CoachingController::class, 'complete'])->name('complete');
            Route::get('/{sessionId}/summary', [CoachingController::class, 'getSummary'])->name('summary');
        });
    });
});
