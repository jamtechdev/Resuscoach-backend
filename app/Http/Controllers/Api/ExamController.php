<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlagQuestionRequest;
use App\Http\Requests\StartExamRequest;
use App\Http\Requests\SubmitAnswerRequest;
use App\Http\Requests\SubmitExamRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Http\Resources\ExamResultsResource;
use App\Models\ExamAttempt;
use App\Models\ExamAnswer;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExamController extends Controller
{
    /**
     * Start a new exam attempt.
     *
     * Randomly selects 40 active questions and creates an exam attempt.
     */
    public function start(StartExamRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Check if user has an in-progress exam
            $inProgressExam = ExamAttempt::where('user_id', $user->id)
                ->where('status', 'in_progress')
                ->first();

            if ($inProgressExam && !$inProgressExam->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have an exam in progress.',
                    'data' => [
                        'exam_id' => $inProgressExam->id,
                    ],
                ], 409);
            }

            // Get 40 random active questions
            $questions = Question::where('is_active', true)
                ->inRandomOrder()
                ->limit(40)
                ->get();

            if ($questions->count() < 40) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not enough questions available. Please contact administrator.',
                ], 503);
            }

            DB::beginTransaction();

            try {
                // Create exam attempt
                $examAttempt = ExamAttempt::create([
                    'user_id' => $user->id,
                    'started_at' => now(),
                    'expires_at' => now()->addMinutes(45), // 45 minutes = 2700 seconds
                    'total_questions' => 40,
                    'status' => 'in_progress',
                ]);

                // Create exam answer records for all questions
                $questionOrder = 1;
                foreach ($questions as $question) {
                    ExamAnswer::create([
                        'attempt_id' => $examAttempt->id,
                        'question_id' => $question->id,
                        'question_order' => $questionOrder++,
                        'is_flagged' => false,
                    ]);
                }

                DB::commit();

                // Load relationships for response
                $examAttempt->load(['answers.question']);

                return response()->json([
                    'success' => true,
                    'message' => 'Exam started successfully.',
                    'data' => new ExamAttemptResource($examAttempt),
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Failed to start exam', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start exam. Please try again.',
            ], 500);
        }
    }

    /**
     * Get exam details.
     *
     * Returns exam information with questions and current answers.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::with(['answers.question', 'user'])
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is expired and update status if needed
            if ($examAttempt->isExpired() && $examAttempt->status === 'in_progress') {
                $this->autoSubmitExam($examAttempt);
                $examAttempt->refresh();
            }

            return response()->json([
                'success' => true,
                'data' => new ExamAttemptResource($examAttempt),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch exam', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch exam details.',
            ], 500);
        }
    }

    /**
     * Submit or update an answer for a question.
     */
    public function submitAnswer(SubmitAnswerRequest $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is still in progress
            if ($examAttempt->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot submit answer. Exam is already completed or abandoned.',
                ], 403);
            }

            // Check if exam is expired
            if ($examAttempt->isExpired()) {
                $this->autoSubmitExam($examAttempt);
                return response()->json([
                    'success' => false,
                    'message' => 'Exam time has expired. Your exam has been automatically submitted.',
                ], 403);
            }

            // Find the answer record
            $examAnswer = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('question_id', $request->question_id)
                ->firstOrFail();

            // Get the question to check correct answer
            $question = Question::findOrFail($request->question_id);

            // Update answer
            $examAnswer->update([
                'selected_option' => $request->selected_option,
                'is_correct' => $request->selected_option === $question->correct_option,
                'answered_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Answer submitted successfully.',
                'data' => new \App\Http\Resources\ExamAnswerResource($examAnswer->load('question')),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam or question not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to submit answer', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit answer. Please try again.',
            ], 500);
        }
    }

    /**
     * Toggle flag on a question.
     */
    public function flagQuestion(FlagQuestionRequest $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is still in progress
            if ($examAttempt->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot flag question. Exam is already completed or abandoned.',
                ], 403);
            }

            // Find the answer record
            $examAnswer = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('question_id', $request->question_id)
                ->firstOrFail();

            // Toggle flag
            $examAnswer->update([
                'is_flagged' => !$examAnswer->is_flagged,
            ]);

            return response()->json([
                'success' => true,
                'message' => $examAnswer->is_flagged ? 'Question flagged.' : 'Question unflagged.',
                'data' => [
                    'question_id' => $examAnswer->question_id,
                    'is_flagged' => $examAnswer->is_flagged,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam or question not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to flag question', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to flag question. Please try again.',
            ], 500);
        }
    }

    /**
     * Submit the exam (end exam early).
     */
    public function submit(SubmitExamRequest $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is already completed
            if ($examAttempt->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam is already completed.',
                ], 403);
            }

            // Submit the exam
            $this->submitExam($examAttempt);

            // Load relationships for response
            $examAttempt->load(['answers.question', 'user']);

            return response()->json([
                'success' => true,
                'message' => 'Exam submitted successfully.',
                'data' => new ExamAttemptResource($examAttempt),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to submit exam', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit exam. Please try again.',
            ], 500);
        }
    }

    /**
     * Get exam results with detailed breakdown.
     */
    public function results(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::with(['answers.question', 'user'])
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Only show results if exam is completed
            if ($examAttempt->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam results are only available after the exam is completed.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => new ExamResultsResource($examAttempt),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch exam results', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch exam results.',
            ], 500);
        }
    }

    /**
     * Submit the exam and calculate score.
     */
    private function submitExam(ExamAttempt $examAttempt): void
    {
        // Calculate score
        $correctCount = ExamAnswer::where('attempt_id', $examAttempt->id)
            ->where('is_correct', true)
            ->count();

        $score = ($correctCount / $examAttempt->total_questions) * 100;

        // Update exam attempt
        $examAttempt->update([
            'status' => 'completed',
            'completed_at' => now(),
            'correct_count' => $correctCount,
            'score' => round($score, 2),
        ]);
    }

    /**
     * Auto-submit exam when time expires.
     */
    private function autoSubmitExam(ExamAttempt $examAttempt): void
    {
        if ($examAttempt->status === 'in_progress') {
            $this->submitExam($examAttempt);
        }
    }
}
