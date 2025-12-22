<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('coaching_dialogues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('coaching_sessions')->onDelete('cascade');
            $table->foreignId('question_id')->constrained()->onDelete('cascade');
            $table->enum('role', ['user', 'assistant']);
            $table->text('content'); // Message text
            $table->enum('step', [
                'initial_reasoning',
                'guideline_reveal',
                'corrected_reasoning',
                'follow_up',
                'complete'
            ]);
            $table->timestamps();

            // Indexes
            $table->index('session_id');
            $table->index('question_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaching_dialogues');
    }
};
