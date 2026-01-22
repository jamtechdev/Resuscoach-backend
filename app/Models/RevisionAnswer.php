<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevisionAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'question_id',
        'selected_option',
        'is_correct',
        'time_taken_seconds',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * Get the revision session this answer belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(RevisionSession::class, 'session_id');
    }

    /**
     * Get the question this answer is for.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Check if the answer is correct and update is_correct field.
     */
    public function checkAnswer(): void
    {
        if ($this->selected_option && $this->question) {
            $this->is_correct = $this->selected_option === $this->question->correct_option;
            $this->save();
        }
    }
}
