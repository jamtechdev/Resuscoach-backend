<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevisionSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'user_id',
        'selected_topics',
        'question_ids',
        'started_at',
        'completed_at',
        'paused_at',
        'current_question_id',
        'current_question_index',
        'total_questions',
        'questions_answered',
        'correct_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'paused_at' => 'datetime',
            'answered_at' => 'datetime',
            'selected_topics' => 'array',
            'question_ids' => 'array',
            'is_correct' => 'boolean',
        ];
    }

    /**
     * Get the exam attempt for this revision session (if started from an exam).
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    /**
     * Get the user for this revision session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the current question.
     */
    public function currentQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'current_question_id');
    }

    /**
     * Get the answers for this revision session.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(RevisionAnswer::class, 'session_id');
    }

    /**
     * Check if the session is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * Pause the session.
     */
    public function pause(): void
    {
        $this->paused_at = now();
        $this->status = 'paused';
        $this->save();
    }

    /**
     * Resume the session.
     */
    public function resume(): void
    {
        if ($this->status === 'paused' && $this->paused_at) {
            $this->paused_at = null;
            $this->status = 'in_progress';
            $this->save();
        }
    }

    /**
     * Complete the session.
     */
    public function complete(): void
    {
        $this->status = 'completed';
        $this->completed_at = now();
        $this->save();
    }
}
