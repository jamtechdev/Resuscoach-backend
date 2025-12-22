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
        'role',
        'content',
        'step',
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
     * Check if this message is from the user.
     */
    public function isUserMessage(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Check if this message is from the assistant.
     */
    public function isAssistantMessage(): bool
    {
        return $this->role === 'assistant';
    }

    /**
     * Scope to get only user messages.
     */
    public function scopeFromUser($query)
    {
        return $query->where('role', 'user');
    }

    /**
     * Scope to get only assistant messages.
     */
    public function scopeFromAssistant($query)
    {
        return $query->where('role', 'assistant');
    }

    /**
     * Scope to get messages for a specific step.
     */
    public function scopeForStep($query, string $step)
    {
        return $query->where('step', $step);
    }
}
