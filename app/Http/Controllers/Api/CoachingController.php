<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CoachingRespondRequest;
use App\Models\CoachingSession;
use App\Models\CoachingDialogue;
use App\Models\CoachingSummary;
use App\Models\ExamAttempt;
use App\Models\ExamAnswer;
use App\Models\Question;
use App\Services\OpenAICoachingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoachingController extends Controller
{
    protected OpenAICoachingService $coachingService;

    public function __construct(OpenAICoachingService $coachingService)
    {
        $this->coachingService = $coachingService;
    }

    /**
     * Log exception and return a consistent JSON error response for better API experience.
     */
    private function logAndRespond(\Throwable $e, string $message, int $status = 500, array $context = []): JsonResponse
    {
        $logContext = array_merge($context, [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);
        if (!app()->environment('production')) {
            $logContext['trace'] = $e->getTraceAsString();
        }

        Log::error($message, $logContext);

        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    /**
     * Start a coaching session from a completed exam attempt.
     *
     * Selects incorrect and flagged questions (targeting ~10 questions)
     * and creates a coaching session.
     */
    public function start(Request $request, int $examId): JsonResponse
    {
        try {
            $user = $request->user();

            // Get the exam attempt
            $examAttempt = ExamAttempt::where('id', $examId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is completed
            if ($examAttempt->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching can only be started for completed exams.',
                ], 403);
            }

            // Check if coaching session already exists
            $existingSession = CoachingSession::where('attempt_id', $examAttempt->id)->first();
            if ($existingSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching session already exists for this exam.',
                    'data' => [
                        'session_id' => $existingSession->id,
                    ],
                ], 409);
            }

            // Get incorrect and flagged questions
            $incorrectAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_correct', false)
                ->with('question')
                ->get();

            $flaggedAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_flagged', true)
                ->with('question')
                ->get();

            // Combine and deduplicate (prioritize incorrect over flagged)
            $questionIds = $incorrectAnswers->pluck('question_id')->toArray();
            $flaggedQuestionIds = $flaggedAnswers->pluck('question_id')->toArray();

            // Add flagged questions that aren't already in incorrect list
            foreach ($flaggedQuestionIds as $flaggedId) {
                if (!in_array($flaggedId, $questionIds)) {
                    $questionIds[] = $flaggedId;
                }
            }

            // Limit to ~10 questions (or all if less than 10)
            $questionIds = array_slice($questionIds, 0, 10);

            if (empty($questionIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No incorrect or flagged questions found. Coaching requires questions to review.',
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Create coaching session
                $coachingSession = CoachingSession::create([
                    'attempt_id' => $examAttempt->id,
                    'user_id' => $user->id,
                    'started_at' => now(),
                    'status' => 'in_progress',
                    'questions_reviewed' => count($questionIds),
                    'current_question_id' => !empty($questionIds) ? $questionIds[0] : null, // Set first question as current
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Coaching session started successfully.',
                    'data' => [
                        'session_id' => $coachingSession->id,
                        'attempt_id' => $examAttempt->id,
                        'questions_to_review' => count($questionIds),
                        'question_ids' => $questionIds,
                        'started_at' => $coachingSession->started_at->toIso8601String(),
                    ],
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
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to start coaching session. Please try again.', 500, [
                'exam_id' => $examId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Get coaching session details.
     */
    public function show(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::with(['attempt', 'dialogues.question', 'summary'])
                ->where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Get questions being reviewed in this session
            $examAttempt = $session->attempt;
            $incorrectAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_correct', false)
                ->with('question')
                ->get();

            $flaggedAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_flagged', true)
                ->with('question')
                ->get();

            $questionIds = $incorrectAnswers->pluck('question_id')->toArray();
            $flaggedQuestionIds = $flaggedAnswers->pluck('question_id')->toArray();

            foreach ($flaggedQuestionIds as $flaggedId) {
                if (!in_array($flaggedId, $questionIds)) {
                    $questionIds[] = $flaggedId;
                }
            }
            $questionIds = array_slice($questionIds, 0, 10);

            // Calculate elapsed time
            $elapsedSeconds = $session->total_duration_seconds;
            if ($session->status === 'in_progress' && $session->started_at) {
                $elapsedSeconds += now()->diffInSeconds($session->started_at);
                if ($session->paused_at) {
                    $elapsedSeconds -= now()->diffInSeconds($session->paused_at);
                }
            }

            $maxDurationSeconds = (int) config('coaching.max_duration_seconds', 1800);
            $remainingSeconds = max(0, $maxDurationSeconds - $elapsedSeconds);

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'attempt_id' => $session->attempt_id,
                    'status' => $session->status,
                    'started_at' => $session->started_at->toIso8601String(),
                    'paused_at' => $session->paused_at?->toIso8601String(),
                    'completed_at' => $session->completed_at?->toIso8601String(),
                    'elapsed_seconds' => $elapsedSeconds,
                    'total_duration_seconds' => $session->total_duration_seconds,
                    'max_duration_seconds' => $maxDurationSeconds,
                    'remaining_seconds' => $remainingSeconds,
                    'questions_reviewed' => $session->questions_reviewed,
                    'questions_to_review' => $questionIds,
                    'current_question_id' => $session->current_question_id,
                    'has_summary' => $session->summary !== null,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to fetch coaching session.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Get all questions for Step 1 (initial load).
     * Returns all questions with their data so frontend can save them locally.
     */
    public function getAllQuestions(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching session is not in progress.',
                ], 403);
            }

            $examAttempt = $session->attempt;

            // Get questions being reviewed in this session
            $incorrectAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_correct', false)
                ->with('question')
                ->get();

            $flaggedAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_flagged', true)
                ->with('question')
                ->get();

            $questionIds = $incorrectAnswers->pluck('question_id')->toArray();
            $flaggedQuestionIds = $flaggedAnswers->pluck('question_id')->toArray();

            foreach ($flaggedQuestionIds as $flaggedId) {
                if (!in_array($flaggedId, $questionIds)) {
                    $questionIds[] = $flaggedId;
                }
            }
            $questionIds = array_slice($questionIds, 0, 10);

            // Get all questions with their answers
            $allQuestions = [];
            foreach ($questionIds as $qId) {
                $question = Question::find($qId);
                $examAnswer = ExamAnswer::where('attempt_id', $examAttempt->id)
                    ->where('question_id', $qId)
                    ->first();

                if ($question && $examAnswer) {
                    // Create Step 1 dialogue record if not exists
                    $existingDialogue = CoachingDialogue::where('session_id', $sessionId)
                        ->where('question_id', $qId)
                        ->where('step_number', 1)
                        ->first();

                    if (!$existingDialogue) {
                        CoachingDialogue::create([
                            'session_id' => $sessionId,
                            'question_id' => $qId,
                            'step_number' => 1,
                            'ai_prompt' => null,
                            'interaction_order' => 1,
                        ]);
                    }

                    $allQuestions[] = [
                        'question_id' => $question->id,
                        'question' => [
                            'id' => $question->id,
                            'stem' => $question->stem,
                            'scenario' => $question->scenario,
                            'options' => [
                                'A' => $question->option_a,
                                'B' => $question->option_b,
                                'C' => $question->option_c,
                                'D' => $question->option_d,
                                'E' => $question->option_e,
                            ],
                        ],
                        'user_answer' => $examAnswer->selected_option,
                        'is_flagged' => $examAnswer->is_flagged,
                        'is_correct' => $examAnswer->is_correct,
                        'step_number' => 1,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $sessionId,
                    'questions' => $allQuestions,
                    'total_questions' => count($allQuestions),
                    'message' => 'All questions loaded. Frontend can now manage navigation and timer.',
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to load questions.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Get the current step for a question in the coaching session.
     * Returns the AI prompt for the current step.
     */
    public function getCurrentStep(Request $request, int $sessionId, int $questionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching session is not in progress.',
                ], 403);
            }

            $question = Question::findOrFail($questionId);
            $examAttempt = $session->attempt;

            // Get user's answer for this question
            $examAnswer = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('question_id', $questionId)
                ->firstOrFail();

            // Get existing dialogues for this question
            $dialogues = CoachingDialogue::where('session_id', $sessionId)
                ->where('question_id', $questionId)
                ->orderBy('step_number')
                ->orderBy('interaction_order')
                ->get();

            // Determine current step (1-5)
            $currentStep = 1;
            if ($dialogues->isNotEmpty()) {
                $lastDialogue = $dialogues->last();

                // Step 1 is special - it just needs to be viewed (dialogue exists)
                if ($lastDialogue->step_number === 1 && !$lastDialogue->user_response) {
                    $currentStep = 2; // Move to Step 2 after Step 1 is viewed
                }
                // Step 2 is special - after user responds, move to Step 3 (no feedback needed)
                elseif ($lastDialogue->step_number === 2 && $lastDialogue->user_response) {
                    $currentStep = 3; // Move to Step 3 after Step 2 response
                }
                // Step 3 is special - after explanation is shown (ai_feedback exists), move to Step 4
                elseif ($lastDialogue->step_number === 3 && $lastDialogue->ai_feedback) {
                    $currentStep = 4; // Move to Step 4 after Step 3 explanation
                }
                // If last step has user response and feedback, move to next step
                elseif ($lastDialogue->user_response && $lastDialogue->ai_feedback) {
                    $currentStep = min(5, $lastDialogue->step_number + 1);
                }
                // If last step has user response but no feedback (and it's not Step 2), we're waiting for AI
                elseif ($lastDialogue->user_response && !$lastDialogue->ai_feedback && $lastDialogue->step_number !== 2) {
                    $currentStep = $lastDialogue->step_number;
                }
                // If last step has AI prompt but no user response, we're waiting for user
                elseif ($lastDialogue->ai_prompt && !$lastDialogue->user_response) {
                    $currentStep = $lastDialogue->step_number;
                }
                // Otherwise, we're on the last step
                else {
                    $currentStep = $lastDialogue->step_number;
                }
            }

            // Step 1: Just display question (no AI call needed)
            if ($currentStep === 1) {
                // Check if Step 1 has already been viewed (to track progress)
                $step1Dialogue = $dialogues->where('step_number', 1)->first();

                // If Step 1 hasn't been viewed yet, create a dialogue record to track it
                if (!$step1Dialogue) {
                    $interactionOrder = $dialogues->max('interaction_order') ?? 0;
                    CoachingDialogue::create([
                        'session_id' => $sessionId,
                        'question_id' => $questionId,
                        'step_number' => 1,
                        'ai_prompt' => null, // Step 1 has no AI prompt
                        'interaction_order' => $interactionOrder + 1,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'step_number' => 1,
                        'question' => [
                            'id' => $question->id,
                            'stem' => $question->stem,
                            'scenario' => $question->scenario,
                            'options' => [
                                'A' => $question->option_a,
                                'B' => $question->option_b,
                                'C' => $question->option_c,
                                'D' => $question->option_d,
                                'E' => $question->option_e,
                            ],
                        ],
                        'user_answer' => $examAnswer->selected_option,
                        'is_flagged' => $examAnswer->is_flagged,
                        'is_correct' => $examAnswer->is_correct,
                        'ai_prompt' => null,
                        'message' => 'Review the question and your answer. Click next to continue.',
                    ],
                ]);
            }

            // Step 2: Generate prompt asking about user's thinking
            if ($currentStep === 2) {
                $existingDialogue = $dialogues->where('step_number', 2)->first();

                if ($existingDialogue && $existingDialogue->ai_prompt) {
                    $aiPrompt = $existingDialogue->ai_prompt;
                } else {
                    // Generate AI prompt
                    $aiPrompt = $this->coachingService->generateStep2Prompt(
                        $question,
                        $examAnswer->selected_option ?? 'N/A'
                    );

                    // Save the dialogue
                    $interactionOrder = $dialogues->max('interaction_order') ?? 0;
                    CoachingDialogue::create([
                        'session_id' => $sessionId,
                        'question_id' => $questionId,
                        'step_number' => 2,
                        'ai_prompt' => $aiPrompt,
                        'interaction_order' => $interactionOrder + 1,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'step_number' => 2,
                        'ai_prompt' => $aiPrompt,
                        'waiting_for_response' => true,
                    ],
                ]);
            }

            // Step 3: Reveal correct answer with explanation
            if ($currentStep === 3) {
                $step2Dialogue = $dialogues->where('step_number', 2)->first();

                // Ensure step 2 is completed (user has responded)
                if (!$step2Dialogue || !$step2Dialogue->user_response) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Please complete step 2 first by responding to the question.',
                        'data' => [
                            'required_step' => 2,
                        ],
                    ], 400);
                }

                $existingDialogue = $dialogues->where('step_number', 3)->first();

                if ($existingDialogue && $existingDialogue->ai_feedback) {
                    $explanation = $existingDialogue->ai_feedback;
                } else {
                    // Generate explanation
                    $explanation = $this->coachingService->generateStep3Explanation(
                        $question,
                        $examAnswer->selected_option ?? 'N/A',
                        $step2Dialogue->user_response
                    );

                    // Save the dialogue
                    $interactionOrder = $dialogues->max('interaction_order') ?? 0;
                    CoachingDialogue::create([
                        'session_id' => $sessionId,
                        'question_id' => $questionId,
                        'step_number' => 3,
                        'ai_feedback' => $explanation,
                        'interaction_order' => $interactionOrder + 1,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'step_number' => 3,
                        'correct_answer' => $question->correct_option,
                        'explanation' => $explanation,
                        'guideline_reference' => $question->guideline_reference,
                        'guideline_url' => $question->guideline_url,
                        'guideline_excerpt' => $question->guideline_excerpt,
                    ],
                ]);
            }

            // Step 4: Ask user to explain correct reasoning
            if ($currentStep === 4) {
                // Ensure step 3 is completed
                $step3Dialogue = $dialogues->where('step_number', 3)->first();
                if (!$step3Dialogue || !$step3Dialogue->ai_feedback) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Please complete step 3 first.',
                        'data' => [
                            'required_step' => 3,
                        ],
                    ], 400);
                }

                $existingDialogue = $dialogues->where('step_number', 4)->first();

                if ($existingDialogue && $existingDialogue->ai_prompt) {
                    $aiPrompt = $existingDialogue->ai_prompt;
                } else {
                    // Generate prompt
                    $aiPrompt = $this->coachingService->generateStep4Prompt($question);

                    // Save the dialogue
                    $interactionOrder = $dialogues->max('interaction_order') ?? 0;
                    CoachingDialogue::create([
                        'session_id' => $sessionId,
                        'question_id' => $questionId,
                        'step_number' => 4,
                        'ai_prompt' => $aiPrompt,
                        'interaction_order' => $interactionOrder + 1,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'step_number' => 4,
                        'ai_prompt' => $aiPrompt,
                        'waiting_for_response' => true,
                    ],
                ]);
            }

            // Step 5: Show AI feedback from step 4 and completion message
            if ($currentStep === 5) {
                // Ensure step 4 is completed (user has responded and feedback is generated)
                $step4Dialogue = $dialogues->where('step_number', 4)->first();
                if (!$step4Dialogue || !$step4Dialogue->user_response || !$step4Dialogue->ai_feedback) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Please complete step 4 first.',
                        'data' => [
                            'required_step' => 4,
                        ],
                    ], 400);
                }

                // Get AI feedback from step 4
                $aiFeedback = $step4Dialogue->ai_feedback;

                // Create step 5 dialogue record if not exists
                $existingDialogue = $dialogues->where('step_number', 5)->first();
                if (!$existingDialogue) {
                    $interactionOrder = $dialogues->max('interaction_order') ?? 0;
                    CoachingDialogue::create([
                        'session_id' => $sessionId,
                        'question_id' => $questionId,
                        'step_number' => 5,
                        'ai_feedback' => $aiFeedback, // Store the feedback reference
                        'interaction_order' => $interactionOrder + 1,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'step_number' => 5,
                        'ai_feedback' => $aiFeedback,
                        'is_question_complete' => true,
                        'message' => 'Great job! You have completed this question. Please proceed to the next question.',
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid step number.',
            ], 400);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session or question not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to get current step. Please try again.', 500, [
                'session_id' => $sessionId,
                'question_id' => $questionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Submit user response and get AI feedback.
     */
    public function respond(CoachingRespondRequest $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching session is not in progress.',
                ], 403);
            }

            $question = Question::findOrFail($request->question_id);

            // Get the dialogue that's waiting for response
            $dialogue = CoachingDialogue::where('session_id', $sessionId)
                ->where('question_id', $request->question_id)
                ->where('step_number', $request->step_number)
                ->whereNull('user_response')
                ->firstOrFail();

            // Update dialogue with user response
            $dialogue->update([
                'user_response' => $request->response,
                'response_type' => $request->response_type ?? 'text',
            ]);

            // Generate AI feedback based on step
            // Step 4: Generate feedback but don't return it yet (will be shown in step 5)
            if ($request->step_number === 4) {
                // Step 4: Validate user's explanation and save feedback
                $aiFeedback = $this->coachingService->generateStep4Feedback(
                    $question,
                    $request->response
                );

                $dialogue->update([
                    'ai_feedback' => $aiFeedback,
                ]);
            }
            // Step 2 and 5 don't need immediate feedback, they move to next step

            return response()->json([
                'success' => true,
                'message' => 'Response submitted successfully.',
                'data' => [
                    'step_number' => $request->step_number,
                    'user_response' => $request->response,
                    'next_step' => $request->step_number === 2 ? 3 : ($request->step_number === 4 ? 5 : null),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session, question, or dialogue not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to submit response. Please try again.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Pause the coaching session.
     */
    public function pause(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only in-progress sessions can be paused.',
                ], 403);
            }

            // Update current question if provided
            if ($request->has('current_question_id')) {
                $session->current_question_id = $request->current_question_id;
            }

            $session->pause();

            return response()->json([
                'success' => true,
                'message' => 'Coaching session paused.',
                'data' => [
                    'session_id' => $session->id,
                    'status' => $session->status,
                    'paused_at' => $session->paused_at->toIso8601String(),
                    'current_question_id' => $session->current_question_id,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to pause session. Please try again.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Update current question (when user navigates between questions).
     */
    public function updateCurrentQuestion(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only update current question for in-progress sessions.',
                ], 403);
            }

            $request->validate([
                'question_id' => 'required|integer|exists:questions,id',
            ]);

            $session->current_question_id = $request->question_id;
            $session->save();

            return response()->json([
                'success' => true,
                'message' => 'Current question updated.',
                'data' => [
                    'session_id' => $session->id,
                    'current_question_id' => $session->current_question_id,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to update current question.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Resume the coaching session.
     */
    public function resume(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
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
                'message' => 'Coaching session resumed.',
                'data' => [
                    'session_id' => $session->id,
                    'status' => $session->status,
                    'paused_at' => $session->paused_at,
                    'current_question_id' => $session->current_question_id,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to resume session. Please try again.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Complete the coaching session and generate summary.
     */
    public function complete(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::with(['attempt', 'dialogues.question'])
                ->where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching session is already completed.',
                ], 403);
            }

            DB::beginTransaction();

            try {
                // Update session status
                $session->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                // Get all dialogues for summary
                $dialogues = $session->dialogues;
                $questionsReviewed = $dialogues->pluck('question_id')->unique()->toArray();
                $topics = $dialogues->pluck('question.topic')->filter()->unique()->toArray();

                // Prepare interactions for summary
                $interactions = $dialogues->map(function ($d) {
                    return ($d->ai_prompt ?? '') . ' ' . ($d->user_response ?? '') . ' ' . ($d->ai_feedback ?? '');
                })->toArray();

                // Generate summary using AI
                $summaryContent = $this->coachingService->generateSummary(
                    $questionsReviewed,
                    $interactions,
                    $topics
                );

                // Extract learning points
                $learningPoints = $this->coachingService->extractLearningPoints($interactions);

                // Extract guidelines
                $questions = Question::whereIn('id', $questionsReviewed)->get();
                $guidelines = $this->coachingService->extractGuidelines($questions);

                // Create or update summary
                CoachingSummary::updateOrCreate(
                    ['session_id' => $sessionId],
                    [
                        'attempt_id' => $session->attempt_id,
                        'user_id' => $user->id,
                        'summary_content' => [
                            'text' => $summaryContent,
                        ],
                        'questions_reviewed' => $questionsReviewed,
                        'key_learning_points' => $learningPoints,
                        'guidelines_referenced' => $guidelines,
                    ]
                );

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Coaching session completed successfully.',
                    'data' => [
                        'session_id' => $session->id,
                        'status' => 'completed',
                        'completed_at' => $session->completed_at->toIso8601String(),
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to complete session. Please try again.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Get coaching session summary.
     */
    public function getSummary(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::with('summary')
                ->where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Summary is only available for completed sessions.',
                ], 403);
            }

            if (!$session->summary) {
                return response()->json([
                    'success' => false,
                    'message' => 'Summary not yet generated. Please complete the session first.',
                ], 404);
            }

            $summary = $session->summary;

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'summary_content' => $summary->summary_content,
                    'questions_reviewed' => $summary->questions_reviewed,
                    'key_learning_points' => $summary->key_learning_points,
                    'guidelines_referenced' => $summary->guidelines_referenced,
                    'created_at' => $summary->created_at->toIso8601String(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to fetch coaching summary.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }
}
