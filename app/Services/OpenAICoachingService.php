<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Question;
use App\Models\ExamAnswer;

class OpenAICoachingService
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private float $temperature;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->model = config('services.openai.model', 'gpt-4');
        $this->maxTokens = config('services.openai.max_tokens', 1000);
        $this->temperature = config('services.openai.temperature', 0.4);
    }

    /**
     * Get the system prompt for the AI coach.
     */
    private function getSystemPrompt(): string
    {
        return "You are an expert MRCEM (Membership of the Royal College of Emergency Medicine) exam coach.
Your role is to help medical professionals prepare for their emergency medicine exams through Socratic questioning and guided learning.

CRITICAL - Accuracy and scope:
- Answer ONLY using the provided scenario, question stem, options, and any guideline/excerpt given to you. Do not add, invent, or assume information that is not explicitly provided.
- Do not use external knowledge beyond what is supplied in the prompt. If something is not in the provided material, do not state it as fact.
- When referencing guidelines, use only the guideline reference, excerpt, or URL that was provided.

Key principles:
- Use evidence-based medicine and clinical guidelines only when they are provided in the question/guideline data
- Ask probing questions to understand the learner's thinking
- Provide constructive feedback without being condescending
- Focus on clinical reasoning and decision-making processes
- Keep responses concise but educational (2-3 paragraphs maximum)
- Use medical terminology appropriately
- Encourage critical thinking";
    }

    /**
     * Generate prompt for Step 2: Ask about user's thinking.
     */
    public function generateStep2Prompt(Question $question, string $userSelectedOption): string
    {
        $prompt = "Use ONLY the information below. Do not add or invent any facts.

Question: {$question->stem}
Scenario: " . ($question->scenario ?? 'N/A') . "

Options:
A. {$question->option_a}
B. {$question->option_b}
C. {$question->option_c}
D. {$question->option_d}
E. {$question->option_e}

The user selected option {$userSelectedOption}.

Generate a friendly, probing question asking the user to explain their thinking process when they selected option {$userSelectedOption}. Base your question only on the scenario and options above. Keep it conversational and supportive (1-2 sentences).";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate explanation for Step 3: Reveal correct answer with guideline reference.
     * Uses the question's stored explanation as the main source so the AI expands it fully (no truncation).
     */
    public function generateStep3Explanation(Question $question, string $userSelectedOption, ?string $userReasoning = null): string
    {
        $guidelineInfo = '';
        if ($question->guideline_reference) {
            $guidelineInfo = "\n\nGuideline Reference: {$question->guideline_reference}";
            if ($question->guideline_url) {
                $guidelineInfo .= "\nGuideline URL: {$question->guideline_url}";
            }
            if ($question->guideline_excerpt) {
                $guidelineInfo .= "\nGuideline Excerpt: {$question->guideline_excerpt}";
            }
        }

        $storedExplanation = trim((string) ($question->explanation ?? ''));
        $explanationBlock = $storedExplanation
            ? "\n\nAuthoritative explanation to use and expand (write a full detailed version based on this):\n{$storedExplanation}"
            : '';

        $userReasoningText = $userReasoning ? "\n\nUser's reasoning: {$userReasoning}" : '';

        $prompt = "You are writing the full detailed explanation for a medical exam question. Use ONLY the information provided below. Do not add or invent facts.

CONTEXT (do not repeat this verbatim in your response):
Question: {$question->stem}
Scenario: " . ($question->scenario ?? 'N/A') . "
Options: A. {$question->option_a} B. {$question->option_b} C. {$question->option_c} D. {$question->option_d} E. {$question->option_e}
Correct answer: Option {$question->correct_option}. User selected: Option {$userSelectedOption}.{$userReasoningText}
{$guidelineInfo}
{$explanationBlock}

TASK: Write a complete, detailed explanation for the learner. Rules:
1. Do NOT repeat the question, scenario, or full list of options in your reply.
2. Do NOT use headings like 'Rationale:', 'Explanation:', or 'Why X is correct:' — start directly with the first sentence of your explanation.
3. You MUST write 2–3 full paragraphs (at least 200 words). Paragraph 1: why the correct option ({$question->correct_option}) is the right choice with clear clinical reasoning. Paragraph 2: how the scenario and any guideline support this. Paragraph 3 (optional): key takeaway or why other options are less appropriate. Write in full sentences; do not stop mid-thought.
4. If an authoritative explanation was provided above, base your response on it and expand it into full paragraphs; do not truncate or leave placeholders.
5. Use only the context above. Be educational, clear, and detailed.";

        return $this->callOpenAI($prompt, 2000);
    }

    /**
     * Generate prompt for Step 4: Ask user to explain correct reasoning.
     * Short static message (no AI call).
     */
    public function generateStep4Prompt(Question $question): string
    {
        return 'Please share your thought process in your own words.';
    }

    /**
     * Generate feedback for Step 4: Listen and respond to the user's explanation — correct, refine, or confirm.
     */
    public function generateStep4Feedback(Question $question, string $userExplanation): string
    {
        $prompt = "You are an exam coach. Use ONLY the information provided below. Do not add or invent facts.

Question: {$question->stem}
Scenario: " . ($question->scenario ?? 'N/A') . "
Correct answer: Option {$question->correct_option}
Correct explanation (authoritative reference): {$question->explanation}

User's explanation in their own words: {$userExplanation}

TASK: Listen to what the user said and respond substantively. You MUST do one or more of the following:
- CORRECT: If they made a clinical or factual error, gently point it out and state the correct point from the reference.
- REFINE: If their reasoning is partly right but incomplete or imprecise, add the missing or clearer part.
- CONFIRM: If they got it right, explicitly confirm what they said well and reinforce the key takeaway.

Do NOT give a generic reply like 'Thank you for sharing.' Your response must directly address their explanation: correct errors, refine reasoning, or confirm understanding. Write 2–3 short paragraphs. Stay encouraging and educational.";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate follow-up question for Step 5: Adjacent concepts.
     * Only pass previousInteractions from the SAME question to avoid cross-question context.
     */
    public function generateStep5FollowUp(Question $question, array $previousInteractions = []): string
    {
        $context = '';
        if (!empty($previousInteractions)) {
            $context = "\n\nPrevious interactions for this question only:\n" . implode("\n", array_slice($previousInteractions, -3));
        }

        $prompt = "Answer only using the question context below. Do not add or invent clinical details.

Question: {$question->stem}
Scenario: " . ($question->scenario ?? 'N/A') . "
Topic: {$question->topic}
Subtopic: {$question->subtopic}{$context}

Generate 1-2 probing questions about adjacent or related concepts that would deepen the learner's understanding, based only on this question's topic and scenario. Keep each question concise (1 sentence each).";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate coaching summary for the entire session.
     */
    public function generateSummary(array $questionsReviewed, array $interactions, array $topics): string
    {
        $questionsSummary = "Questions reviewed: " . count($questionsReviewed);
        $topicsSummary = "Topics covered: " . implode(', ', array_unique($topics));

        $prompt = "Generate a comprehensive coaching session summary for an MRCEM exam preparation session.

{$questionsSummary}
{$topicsSummary}

Key interactions and learning points from the session:
" . implode("\n", array_slice($interactions, 0, 10)) . "

Create a structured summary that includes:
1. Overall performance insights
2. Key learning points (3-5 bullet points)
3. Areas for improvement
4. Recommended next steps for study

Keep it professional, encouraging, and actionable (4-5 paragraphs).";

        return $this->callOpenAI($prompt);
    }

    /**
     * Make API call to OpenAI.
     * @param int|null $maxTokensOverride Use for long responses (e.g. detailed explanation). Default from config.
     */
    private function callOpenAI(string $userPrompt, ?int $maxTokensOverride = null): string
    {
        if (empty($this->apiKey)) {
            Log::error('OpenAI API key is not configured');
            throw new \Exception('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
        }

        $maxTokens = $maxTokensOverride ?? $this->maxTokens;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->getSystemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => $userPrompt,
                    ],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => $this->temperature,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('OpenAI API request failed: ' . $response->body());
            }

            $data = $response->json();

            if (!isset($data['choices'][0]['message']['content'])) {
                Log::error('OpenAI API response missing content', ['response' => $data]);
                throw new \Exception('Invalid response from OpenAI API');
            }

            return trim($data['choices'][0]['message']['content']);
        } catch (\Exception $e) {
            Log::error('OpenAI API error', [
                'message' => $e->getMessage(),
                'prompt' => substr($userPrompt, 0, 200),
            ]);
            throw $e;
        }
    }

    /**
     * Extract key learning points from interactions.
     */
    public function extractLearningPoints(array $interactions): array
    {
        if (empty($interactions)) {
            return [];
        }

        $prompt = "From these coaching interactions, extract 3-5 key learning points as concise bullet points:\n\n"
            . implode("\n\n", array_slice($interactions, 0, 5));

        $response = $this->callOpenAI($prompt);

        // Parse bullet points from response
        $points = [];
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[-•*]\s*(.+)$/', $line, $match) || preg_match('/^\d+\.\s*(.+)$/', $line, $match)) {
                $points[] = trim($match[1]);
            }
        }

        return array_slice($points, 0, 5);
    }

    /**
     * Extract guidelines referenced in the session.
     */
    public function extractGuidelines($questions): array
    {
        $guidelines = [];

        foreach ($questions as $question) {
            // Handle both objects and arrays
            $guidelineRef = is_array($question) ? ($question['guideline_reference'] ?? null) : $question->guideline_reference;

            if ($guidelineRef) {
                $guidelines[] = [
                    'reference' => $guidelineRef,
                    'source' => is_array($question) ? ($question['guideline_source'] ?? null) : $question->guideline_source,
                    'url' => is_array($question) ? ($question['guideline_url'] ?? null) : $question->guideline_url,
                ];
            }
        }

        return array_unique($guidelines, SORT_REGULAR);
    }
}
