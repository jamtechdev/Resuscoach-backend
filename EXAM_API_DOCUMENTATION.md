 Exam API Documentation for Frontend

## Overview
This document explains how to implement the exam feature in the frontend. All exam-related APIs require authentication (Bearer token).

---

## 1. Get Available Topics (Optional)

Before starting an exam, you can fetch available topics to show to users.

**Endpoint:** `GET /api/v1/exams/topics`
**Authentication:** Not required

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "topic": "Cardiology",
      "subtopics": ["ACS", "STEMI", "Arrhythmias"],
      "question_count": 150
    },
    {
      "topic": "Respiratory",
      "subtopics": [],
      "question_count": 80
    }
  ]
}
```

**Note:** If a topic has no subtopics, `subtopics` will be an empty array `[]`.

---

## 2. Start Exam

**Endpoint:** `POST /api/v1/exams/start`
**Authentication:** Required

**Request Body:**
```json
{
  "topic": "Cardiology",      // Optional - main topic name
  "subtopic": "ACS"           // Optional - subtopic name (only if topic is provided)
}
```

**Important:**
- Both `topic` and `subtopic` are optional
- If you send `subtopic`, you must also send `topic`
- If you don't send anything, exam will have random questions from all topics
- Exam will use all available questions (up to 40, or less if fewer are available)

**Success Response (201):**
```json
{
  "success": true,
  "message": "Exam started successfully.",
  "data": {
    "id": 123,
    "status": "in_progress",
    "total_questions": 40,
    "started_at": "2024-12-22T10:00:00Z",
    "expires_at": "2024-12-22T10:45:00Z",
    "remaining_seconds": 2700,
    "questions": [
      {
        "id": 1,
        "stem": "Question text here...",
        "options": {
          "A": "Answer option A",
          "B": "Answer option B",
          "C": "Answer option C",
          "D": "Answer option D",
          "E": "Answer option E"
        },
        "has_image": false,
        "image_url": null,
        "topic": "Cardiology",
        "subtopic": "ACS",
        "difficulty": "Medium"
      }
      // ... more questions
    ],
    "answers": [
      {
        "question_id": 1,
        "question_order": 1,
        "selected_option": null,
        "is_flagged": false
      }
      // ... more answers
    ]
  }
}
```

**Error Response - Exam Already in Progress (409):**
```json
{
  "success": false,
  "message": "You already have an exam in progress.",
  "data": {
    "exam_id": 122
  }
}
```

---

## 3. Navigate Questions (Frontend Only)

**No API call needed!**

When the exam starts, you receive ALL questions in the `questions` array. Store this array in your frontend state and navigate between questions client-side.

**Example:**
```javascript
// Store questions from start response
const questions = examData.data.questions; // Array of all questions
let currentIndex = 0;

// Show current question
const currentQuestion = questions[currentIndex];

// Navigate to next question
function nextQuestion() {
  if (currentIndex < questions.length - 1) {
    currentIndex++;
    displayQuestion(questions[currentIndex]);
  }
}

// Navigate to previous question
function previousQuestion() {
  if (currentIndex > 0) {
    currentIndex--;
    displayQuestion(questions[currentIndex]);
  }
}

// Jump to specific question
function goToQuestion(index) {
  currentIndex = index;
  displayQuestion(questions[currentIndex]);
}
```

**Important:**
- Questions are already sorted by `question_order` in the response
- Use `question_order` from the `answers` array to match questions with user's answers
- No need to call API for navigation - do it all client-side

---

## 4. Submit Answer

**Endpoint:** `POST /api/v1/exams/{id}/answer`
**Authentication:** Required
**URL Parameter:** `{id}` = exam ID

**Request Body:**
```json
{
  "question_id": 123,        // Required - ID of the question
  "selected_option": "B"     // Required - Must be "A", "B", "C", "D", or "E"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Answer submitted successfully.",
  "data": {
    "question_id": 123,
    "selected_option": "B",
    "is_correct": true,
    "is_flagged": false,
    "answered_at": "2024-12-22T10:05:00Z"
  }
}
```

**Error Responses:**
- **403:** Exam is completed or expired
- **404:** Exam or question not found

**Note:** You can call this API multiple times for the same question to update the answer.

---

## 5. Flag/Unflag Question

**Endpoint:** `POST /api/v1/exams/{id}/flag`
**Authentication:** Required
**URL Parameter:** `{id}` = exam ID

**Request Body:**
```json
{
  "question_id": 123    // Required - ID of the question
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Question flagged.",  // or "Question unflagged."
  "data": {
    "question_id": 123,
    "is_flagged": true
  }
}
```

**Note:** This toggles the flag status. If question is flagged, it becomes unflagged, and vice versa.

---

## 6. Submit Exam (Complete Exam)

**Endpoint:** `POST /api/v1/exams/{id}/submit`
**Authentication:** Required
**URL Parameter:** `{id}` = exam ID

**Request Body:** None (empty body)

**Success Response (200):**
```json
{
  "success": true,
  "message": "Exam submitted successfully.",
  "data": {
    "id": 123,
    "status": "completed",
    "score": 85.5,
    "correct_count": 34,
    "total_questions": 40,
    "percentage": 85.5
    // ... full exam data with results
  }
}
```

---

## 7. Get Exam Details (Reload Exam)

**Endpoint:** `GET /api/v1/exams/{id}`
**Authentication:** Required
**URL Parameter:** `{id}` = exam ID

**Use this when:**
- User refreshes the page
- Need to reload exam data
- Checking exam status

**Response:** Same format as start exam response, but includes current answers and status.

---

## 8. Get Exam Results

**Endpoint:** `GET /api/v1/exams/{id}/results`
**Authentication:** Required
**URL Parameter:** `{id}` = exam ID

**Response:** Detailed results with correct answers, explanations, and score breakdown.

**Note:** Only available after exam is completed.

---

## 9. Check In-Progress Exam

**Endpoint:** `GET /api/v1/exams/check-in-progress`
**Authentication:** Required

**Use this when:**
- User opens the app
- Check if they have an unfinished exam

**Response:**
```json
{
  "success": true,
  "has_in_progress": true,
  "data": {
    // Full exam data if exam exists
  }
}
```

---

## Complete Frontend Flow Example

```javascript
// 1. Start Exam
async function startExam(topic, subtopic) {
  const response = await fetch('/api/v1/exams/start', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({
      topic: topic || null,
      subtopic: subtopic || null
    })
  });

  const data = await response.json();

  if (data.success) {
    // Store exam data
    const examId = data.data.id;
    const questions = data.data.questions;
    const answers = data.data.answers;

    // Initialize exam state
    setCurrentExam({ examId, questions, answers });
    setCurrentQuestionIndex(0);

    // Start timer using remaining_seconds
    startTimer(data.data.remaining_seconds);
  }
}

// 2. Navigate Questions (No API call)
function showQuestion(index) {
  const question = questions[index];
  const answer = answers.find(a => a.question_id === question.id);

  displayQuestion(question, answer);
}

// 3. Submit Answer
async function submitAnswer(questionId, selectedOption) {
  const response = await fetch(`/api/v1/exams/${examId}/answer`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({
      question_id: questionId,
      selected_option: selectedOption
    })
  });

  const data = await response.json();
  // Update local state with answer
}

// 4. Flag Question
async function toggleFlag(questionId) {
  await fetch(`/api/v1/exams/${examId}/flag`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({ question_id: questionId })
  });
}

// 5. Submit Exam
async function submitExam() {
  const response = await fetch(`/api/v1/exams/${examId}/submit`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });

  const data = await response.json();
  // Redirect to results page
}
```

---

## Important Notes

1. **All questions are returned when exam starts** - No need to call API for each question
2. **Navigation is client-side** - Use the questions array from start response
3. **Timer management** - Use `remaining_seconds` or `expires_at` from start response
4. **Answer tracking** - Match `question_id` from questions with answers array
5. **One exam at a time** - Check for in-progress exam before allowing new exam
6. **Auto-submit** - Exam auto-submits when time expires (45 minutes)

---

## Error Handling

- **409 Conflict:** User has exam in progress - redirect to that exam
- **403 Forbidden:** Exam completed/expired or action not allowed
- **404 Not Found:** Exam or question doesn't exist
- **422 Unprocessable:** Validation error (check error message)
- **500 Server Error:** Try again later

---

## Base URL

All endpoints are prefixed with `/api/v1/`

Example: `POST /api/v1/exams/start`

---

## Questions?

If you need clarification on any endpoint or have issues, please contact the backend team.

