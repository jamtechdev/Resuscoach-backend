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
        'completed_at',
        'paused_at',
        'total_duration_seconds',
        'questions_reviewed',
        'current_question_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }


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
            $this->total_duration_seconds += $pausedSeconds;
            $this->paused_at = null;
            $this->status = 'in_progress';
            $this->save();
        }
    }

    /**
     * Scope to get only active sessions.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['in_progress', 'paused']);
    }
}
