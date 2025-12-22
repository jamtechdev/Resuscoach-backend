<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'started_at',
        'completed_at',
        'expires_at',
        'score',
        'total_questions',
        'correct_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'score' => 'decimal:2',
        ];
    }

    /**
     * Get the user who took this exam.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the answers for this exam attempt.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class, 'attempt_id');
    }

    /**
     * Get the coaching sessions for this exam attempt.
     */
    public function coachingSessions(): HasMany
    {
        return $this->hasMany(CoachingSession::class, 'attempt_id');
    }

    /**
     * Get the coaching summary for this exam attempt.
     */
    public function coachingSummary(): HasOne
    {
        return $this->hasOne(CoachingSummary::class, 'attempt_id');
    }

    /**
     * Check if the exam is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast() && $this->status === 'in_progress';
    }

    /**
     * Check if the exam is still in progress.
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress' && !$this->isExpired();
    }

    /**
     * Get the remaining time in seconds.
     */
    public function getRemainingSecondsAttribute(): int
    {
        if ($this->status !== 'in_progress') {
            return 0;
        }

        $remaining = $this->expires_at->diffInSeconds(now(), false);
        return max(0, -$remaining);
    }

    /**
     * Calculate and update the score.
     */
    public function calculateScore(): void
    {
        $this->correct_count = $this->answers()->where('is_correct', true)->count();
        $this->score = ($this->correct_count / $this->total_questions) * 100;
        $this->save();
    }

    /**
     * Scope to get only in-progress attempts.
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope to get only completed attempts.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
