<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CoachingSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'user_id',
        'started_at',
        'ended_at',
        'expires_at',
        'status',
        'questions_reviewed',
        'current_question_id',
        'current_step',
        'paused_at',
        'total_pause_seconds',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'expires_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    /**
     * Coaching steps in order.
     */
    public const STEPS = [
        'initial_reasoning',
        'guideline_reveal',
        'corrected_reasoning',
        'follow_up',
        'complete',
    ];

    /**
     * Get the exam attempt for this coaching session.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    /**
     * Get the user for this coaching session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the current question being discussed.
     */
    public function currentQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'current_question_id');
    }

    /**
     * Get the dialogues for this coaching session.
     */
    public function dialogues(): HasMany
    {
        return $this->hasMany(CoachingDialogue::class, 'session_id');
    }

    /**
     * Get the summary for this coaching session.
     */
    public function summary(): HasOne
    {
        return $this->hasOne(CoachingSummary::class, 'session_id');
    }

    /**
     * Check if the session is expired.
     */
    public function isExpired(): bool
    {
        if ($this->status === 'paused') {
            return false; // Paused sessions don't expire
        }
        return $this->expires_at->isPast() && $this->status === 'in_progress';
    }

    /**
     * Check if the session is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'in_progress' && !$this->isExpired();
    }

    /**
     * Get the effective remaining time accounting for pauses.
     */
    public function getRemainingSecondsAttribute(): int
    {
        if (!in_array($this->status, ['in_progress', 'paused'])) {
            return 0;
        }

        $remaining = $this->expires_at->diffInSeconds(now(), false);
        return max(0, -$remaining);
    }

    /**
     * Pause the session.
     */
    public function pause(): void
    {
        if ($this->status === 'in_progress') {
            $this->paused_at = now();
            $this->status = 'paused';
            $this->save();
        }
    }

    /**
     * Resume the session.
     */
    public function resume(): void
    {
        if ($this->status === 'paused' && $this->paused_at) {
            $pausedSeconds = $this->paused_at->diffInSeconds(now());
            $this->total_pause_seconds += $pausedSeconds;
            $this->expires_at = $this->expires_at->addSeconds($pausedSeconds);
            $this->paused_at = null;
            $this->status = 'in_progress';
            $this->save();
        }
    }

    /**
     * Move to the next coaching step.
     */
    public function nextStep(): ?string
    {
        $currentIndex = array_search($this->current_step, self::STEPS);
        if ($currentIndex !== false && $currentIndex < count(self::STEPS) - 1) {
            $this->current_step = self::STEPS[$currentIndex + 1];
            $this->save();
            return $this->current_step;
        }
        return null;
    }

    /**
     * Scope to get only active sessions.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['in_progress', 'paused']);
    }
}
