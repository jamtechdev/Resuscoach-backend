<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachingSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'attempt_id',
        'user_id',
        'questions_reviewed',
        'key_learning_points',
        'guidelines_referenced',
        'overall_feedback',
    ];

    protected function casts(): array
    {
        return [
            'questions_reviewed' => 'array',
            'key_learning_points' => 'array',
            'guidelines_referenced' => 'array',
        ];
    }

    /**
     * Get the coaching session this summary belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CoachingSession::class, 'session_id');
    }

    /**
     * Get the exam attempt this summary is for.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    /**
     * Get the user this summary belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the count of questions reviewed.
     */
    public function getQuestionsCountAttribute(): int
    {
        return is_array($this->questions_reviewed) ? count($this->questions_reviewed) : 0;
    }

    /**
     * Get the count of learning points.
     */
    public function getLearningPointsCountAttribute(): int
    {
        return is_array($this->key_learning_points) ? count($this->key_learning_points) : 0;
    }
}
