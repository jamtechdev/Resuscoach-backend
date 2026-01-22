<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RevisionAnswerResource;
use App\Http\Resources\RevisionQuestionResource;
use App\Http\Resources\RevisionSessionResource;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\RevisionAnswer;
use App\Models\RevisionSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RevisionController extends Controller
{
    /**
     * Start a new revision session.
     * Can be started after exam completion or as a standalone revision.
     * User selects topics to revise.
     */
    public function start(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $request->validate([
                'topics' => 'required|array|min:1',
                'topics.*' => 'required|string',
                'attempt_id' => 'nullable|integer|exists:exam_attempts,id',
            ]);

            // If attempt_id is provided and not null, verify it belongs to the user and is completed
            if ($request->has('attempt_id') && $request->attempt_id !== null) {
                $examAttempt = ExamAttempt::where('id', $request->attempt_id)
                    ->where('user_id', $user->id)
                    ->firstOrFail();

                if ($examAttempt->status !== 'completed') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Revision can only be started for completed exams.',
                    ], 403);
                }
            }

            // Get questions based on selected topics
            $questions = Question::where('is_active', true)
                ->whereIn('topic', $request->topics)
                ->inRandomOrder()
                ->get();

            if ($questions->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No questions found for the selected topics.',
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Store question IDs in order
                $questionIds = $questions->pluck('id')->toArray();

                // Create revision session
                $revisionSession = RevisionSession::create([
                    'attempt_id' => $request->attempt_id ?? null,
                    'user_id' => $user->id,
                    'selected_topics' => $request->topics,
                    'question_ids' => $questionIds,
                    'started_at' => now(),
                    'status' => 'in_progress',
                    'total_questions' => $questions->count(),
                    'current_question_index' => 0,
                    'current_question_id' => $questions->first()->id,
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Revision session started successfully.',
                    'data' => new RevisionSessionResource($revisionSession),
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found.',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to start revision session', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start revision session. Please try again.',
            ], 500);
        }
    }

    /**
     * Get revision session details.
     */
    public function show(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = RevisionSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => new RevisionSessionResource($session),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Revision session not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch revision session', [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch revision session.',
            ], 500);
        }
    }

    /**
     * Get the current question for the revision session.
     * Returns one question at a time with all 5 options.
     * Timer is 60 seconds (handled on frontend).
     */
    public function getCurrentQuestion(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = RevisionSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Revision session is not in progress.',
                ], 403);
            }

            // Check if all questions are answered
            if ($session->questions_answered >= $session->total_questions) {
                return response()->json([
                    'success' => false,
                    'message' => 'All questions have been answered.',
                    'data' => [
                        'is_complete' => true,
                    ],
                ], 400);
            }

            // Get current question
            $question = Question::findOrFail($session->current_question_id);

            // Check if this question has already been answered
            $existingAnswer = RevisionAnswer::where('session_id', $sessionId)
                ->where('question_id', $question->id)
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'question' => new RevisionQuestionResource($question),
                    'question_number' => $session->current_question_index + 1,
                    'total_questions' => $session->total_questions,
                    'timer_seconds' => 60, // 60 second timer
                    'has_answered' => $existingAnswer !== null,
                    'previous_answer' => $existingAnswer ? [
                        'selected_option' => $existingAnswer->selected_option,
                        'is_correct' => $existingAnswer->is_correct,
                    ] : null,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Revision session or question not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to get current question', [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get current question.',
            ], 500);
        }
    }

    /**
     * Submit an answer for the current question.
     * Returns the correct answer and explanation if user selected wrong option.
     */
    public function submitAnswer(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $request->validate([
                'question_id' => 'required|integer|exists:questions,id',
                'selected_option' => 'required|in:A,B,C,D,E',
                'time_taken_seconds' => 'nullable|integer|min:0|max:60',
            ]);

            $session = RevisionSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Revision session is not in progress.',
                ], 403);
            }

            // Verify the question is the current question
            if ($session->current_question_id != $request->question_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'This is not the current question.',
                ], 400);
            }

            $question = Question::findOrFail($request->question_id);
            $isCorrect = $request->selected_option === $question->correct_option;

            DB::beginTransaction();

            try {
                // Check if this question was already answered
                $existingAnswer = RevisionAnswer::where('session_id', $sessionId)
                    ->where('question_id', $request->question_id)
                    ->first();

                $isNewAnswer = $existingAnswer === null;
                $wasCorrect = $existingAnswer?->is_correct ?? false;

                // Create or update answer
                $revisionAnswer = RevisionAnswer::updateOrCreate(
                    [
                        'session_id' => $sessionId,
                        'question_id' => $request->question_id,
                    ],
                    [
                        'selected_option' => $request->selected_option,
                        'is_correct' => $isCorrect,
                        'time_taken_seconds' => $request->time_taken_seconds ?? 0,
                        'answered_at' => now(),
                    ]
                );

                // Update session statistics
                // Only increment questions_answered if this is a new answer
                if ($isNewAnswer) {
                    $session->questions_answered += 1;
                }

                // Update correct_count
                // If it was correct before and now it's wrong, decrement
                if ($wasCorrect && !$isCorrect) {
                    $session->correct_count = max(0, $session->correct_count - 1);
                }
                // If it was wrong before and now it's correct, increment
                elseif (!$wasCorrect && $isCorrect) {
                    $session->correct_count += 1;
                }
                // If it's a new answer and it's correct, increment
                elseif ($isNewAnswer && $isCorrect) {
                    $session->correct_count += 1;
                }

                $session->save();

                DB::commit();

                // Load question relationship for resource
                $revisionAnswer->load('question');

                return response()->json([
                    'success' => true,
                    'message' => 'Answer submitted successfully.',
                    'data' => new RevisionAnswerResource($revisionAnswer),
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Revision session or question not found.',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to submit answer', [
                'session_id' => $sessionId,
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
     * Get the next question after submitting an answer.
     * Moves to the next question in the sequence.
     */
    public function getNextQuestion(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = RevisionSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Revision session is not in progress.',
                ], 403);
            }

            // Check if all questions are answered
            if ($session->questions_answered >= $session->total_questions) {
                return response()->json([
                    'success' => false,
                    'message' => 'All questions have been answered.',
                    'data' => [
                        'is_complete' => true,
                        'total_questions' => $session->total_questions,
                        'correct_count' => $session->correct_count,
                        'score' => $session->total_questions > 0
                            ? round(($session->correct_count / $session->total_questions) * 100, 2)
                            : 0,
                    ],
                ], 400);
            }

            // Get question IDs from session (stored when session was created)
            $questionIds = $session->question_ids ?? [];

            if (empty($questionIds)) {
                // Fallback: get questions if question_ids not stored (for backward compatibility)
                $questionIds = Question::where('is_active', true)
                    ->whereIn('topic', $session->selected_topics)
                    ->inRandomOrder()
                    ->pluck('id')
                    ->toArray();

                // Update session with question IDs
                $session->question_ids = $questionIds;
                $session->save();
            }

            // Get next question index
            $nextIndex = $session->current_question_index + 1;

            if ($nextIndex >= count($questionIds)) {
                // All questions answered
                $session->complete();
                return response()->json([
                    'success' => false,
                    'message' => 'All questions have been answered.',
                    'data' => [
                        'is_complete' => true,
                        'total_questions' => $session->total_questions,
                        'correct_count' => $session->correct_count,
                        'score' => $session->total_questions > 0
                            ? round(($session->correct_count / $session->total_questions) * 100, 2)
                            : 0,
                    ],
                ], 400);
            }

            // Get next question
            $nextQuestionId = $questionIds[$nextIndex];
            $nextQuestion = Question::findOrFail($nextQuestionId);

            // Update session
            $session->current_question_id = $nextQuestionId;
            $session->current_question_index = $nextIndex;
            $session->save();

            // Check if this question has already been answered
            $existingAnswer = RevisionAnswer::where('session_id', $sessionId)
                ->where('question_id', $nextQuestion->id)
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'question' => new RevisionQuestionResource($nextQuestion),
                    'question_number' => $nextIndex + 1,
                    'total_questions' => $session->total_questions,
                    'timer_seconds' => 60,
                    'has_answered' => $existingAnswer !== null,
                    'previous_answer' => $existingAnswer ? [
                        'selected_option' => $existingAnswer->selected_option,
                        'is_correct' => $existingAnswer->is_correct,
                    ] : null,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Revision session or question not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to get next question', [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get next question.',
            ], 500);
        }
    }

    /**
     * Complete/end the revision session.
     */
    public function complete(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = RevisionSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Revision session is already completed.',
                ], 403);
            }

            $session->complete();

            return response()->json([
                'success' => true,
                'message' => 'Revision session completed successfully.',
                'data' => new RevisionSessionResource($session),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Revision session not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to complete revision session', [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete revision session.',
            ], 500);
        }
    }

    /**
     * Pause the revision session.
     */
    public function pause(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = RevisionSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only in-progress sessions can be paused.',
                ], 403);
            }

            $session->pause();

            return response()->json([
                'success' => true,
                'message' => 'Revision session paused.',
                'data' => [
                    'session_id' => $session->id,
                    'status' => $session->status,
                    'paused_at' => $session->paused_at->toIso8601String(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Revision session not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to pause revision session', [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to pause session.',
            ], 500);
        }
    }

    /**
     * Resume the revision session.
     */
    public function resume(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = RevisionSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'paused') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only paused sessions can be resumed.',
                ], 403);
            }

            $session->resume();

            return response()->json([
                'success' => true,
                'message' => 'Revision session resumed.',
                'data' => [
                    'session_id' => $session->id,
                    'status' => $session->status,
                    'paused_at' => $session->paused_at,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Revision session not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to resume revision session', [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resume session.',
            ], 500);
        }
    }
}
