<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'question_id',
        'question_order',
        'selected_option',
        'is_correct',
        'is_flagged',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'is_flagged' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * Get the exam attempt this answer belongs to.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
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
        if ($this->selected_option) {
            $this->is_correct = $this->selected_option === $this->question->correct_option;
            $this->save();
        }
    }

    /**
     * Scope to get only answered questions.
     */
    public function scopeAnswered($query)
    {
        return $query->whereNotNull('selected_option');
    }

    /**
     * Scope to get only flagged questions.
     */
    public function scopeFlagged($query)
    {
        return $query->where('is_flagged', true);
    }

    /**
     * Scope to get only incorrect answers.
     */
    public function scopeIncorrect($query)
    {
        return $query->where('is_correct', false);
    }
}
