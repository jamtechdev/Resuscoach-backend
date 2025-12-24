<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachingDialogue extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'question_id',
        'step_number',
        'ai_prompt',
        'user_response',
        'response_type',
        'ai_feedback',
        'interaction_order',
    ];

    /**
     * Get the coaching session this dialogue belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CoachingSession::class, 'session_id');
    }

    /**
     * Get the question this dialogue is about.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Scope to get interactions for a specific step number.
     */
    public function scopeForStepNumber($query, int $stepNumber)
    {
        return $query->where('step_number', $stepNumber);
    }

    /**
     * Scope to get interactions ordered by interaction order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('interaction_order');
    }
}
