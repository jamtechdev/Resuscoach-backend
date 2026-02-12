# AI Coaching: Prompts and Model

This document describes the **prompts** and **model** used for AI coaching and exam-review feedback so the client can tweak and test them.

---

## Model

- **Model name:** Configured via `OPENAI_MODEL` in `.env`.
- **Default:** `gpt-4` (from `config/services.php`).
- **Config location:** `config/services.php` → `services.openai.model` (env: `OPENAI_MODEL`).

Other OpenAI options in config:
- `OPENAI_MAX_TOKENS` (default: 1000)
- `OPENAI_TEMPERATURE` (default: 0.7)

---

## System prompt (used for all coaching calls)

This is the **system** message sent on every OpenAI request for coaching:

```
You are an expert MRCEM (Membership of the Royal College of Emergency Medicine) exam coach.
Your role is to help medical professionals prepare for their emergency medicine exams through Socratic questioning and guided learning.

Key principles:
- Use evidence-based medicine and clinical guidelines
- Reference NICE, ESC, AHA, ACC guidelines when relevant
- Ask probing questions to understand the learner's thinking
- Provide constructive feedback without being condescending
- Focus on clinical reasoning and decision-making processes
- Keep responses concise but educational (2-3 paragraphs maximum)
- Use medical terminology appropriately
- Encourage critical thinking
```

**Code:** `App\Services\OpenAICoachingService::getSystemPrompt()` (private).

---

## User prompts (per step)

The **user** message content varies by step. Variables like `{$question->stem}` are filled from the current question and user input.

### Step 2 – Ask about user’s thinking

**Purpose:** Ask the user to explain why they chose their selected option.

**Template (conceptually):**
```
The user selected option {userSelectedOption} for this question:

Question: {question.stem}
Scenario: {question.scenario or 'N/A'}

Options:
A. {option_a}
B. {option_b}
C. {option_c}
D. {option_d}
E. {option_e}

Generate a friendly, probing question asking the user to explain their thinking process when they selected option {userSelectedOption}.
The question should encourage reflection and help identify any misconceptions. Keep it conversational and supportive (1-2 sentences).
```

**Code:** `OpenAICoachingService::generateStep2Prompt()`

---

### Step 3 – Reveal correct answer and explain

**Purpose:** Explain why the correct option is right and, if needed, why the user’s choice was not optimal.

**Template (conceptually):**
```
The correct answer is option {question.correct_option}.

Question: {question.stem}
Scenario: {question.scenario or 'N/A'}

User selected: Option {userSelectedOption}
Correct answer: Option {question.correct_option}
[Optional: User's reasoning: {userReasoning}]

[If question has guideline: Guideline Reference, URL, Excerpt]

Explain why option {question.correct_option} is correct, referencing the guideline when applicable.
If the user selected incorrectly, gently explain why their choice was not optimal and what the correct reasoning should be.
Keep it educational and supportive (2-3 paragraphs).
```

**Code:** `OpenAICoachingService::generateStep3Explanation()`

---

### Step 4 – Ask user to explain correct reasoning

**Purpose:** Ask the user to explain the correct reasoning in their own words.

**Template:**
```
The correct answer has been explained. Now ask the user to explain the correct reasoning in their own words.
This helps reinforce learning. Make it encouraging and supportive (1-2 sentences).
```

**Code:** `OpenAICoachingService::generateStep4Prompt()`

---

### Step 4 feedback – Validate user’s explanation

**Purpose:** Give feedback on the user’s explanation of the correct reasoning.

**Template (conceptually):**
```
The user has provided their explanation of the correct reasoning:

User's explanation: {userExplanation}

Question: {question.stem}
Correct answer: Option {question.correct_option}
Correct explanation: {question.explanation}

Provide constructive feedback on the user's explanation.
- Acknowledge what they got right
- Gently correct any misconceptions
- Reinforce key learning points
- Keep it encouraging (2-3 paragraphs)
```

**Code:** `OpenAICoachingService::generateStep4Feedback()`

---

### Step 5 – Adjacent concepts (follow-up questions)

**Purpose:** Generate 1–2 follow-up questions on related concepts.

**Template (conceptually):**
```
Based on this question about {question.topic} - {question.subtopic}:

Question: {question.stem}
Topic: {question.topic}
Subtopic: {question.subtopic}
[Optional: Previous interactions in this session (last 3)]

Generate 1-2 probing questions about adjacent or related concepts that would deepen the learner's understanding.
These should be thought-provoking but not overly complex. Keep each question concise (1 sentence each).
```

**Code:** `OpenAICoachingService::generateStep5FollowUp()`

---

### Session summary

**Purpose:** End-of-session summary (overall performance, learning points, areas for improvement, next steps).

**Template (conceptually):**
```
Generate a comprehensive coaching session summary for an MRCEM exam preparation session.

Questions reviewed: {count}
Topics covered: {comma-separated topics}

Key interactions and learning points from the session:
{first 10 interaction snippets}

Create a structured summary that includes:
1. Overall performance insights
2. Key learning points (3-5 bullet points)
3. Areas for improvement
4. Recommended next steps for study

Keep it professional, encouraging, and actionable (4-5 paragraphs).
```

**Code:** `OpenAICoachingService::generateSummary()`

---

### Extract learning points (internal)

**Purpose:** Parse 3–5 bullet learning points from interaction text.

**Template:**
```
From these coaching interactions, extract 3-5 key learning points as concise bullet points:

{first 5 interaction snippets}
```

**Code:** `OpenAICoachingService::extractLearningPoints()`

---

## Changing the model or prompts

- **Model:** Set `OPENAI_MODEL` in `.env` (e.g. `gpt-4`, `gpt-4-turbo`, `gpt-3.5-turbo`). No code change needed.
- **Prompts:** Edit the strings in `app/Services/OpenAICoachingService.php` in the methods listed above. The system prompt is in `getSystemPrompt()`; user prompts are in each `generate*` method.
