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
            $table->unsignedInteger('step_number'); // Position in coaching flow (1-5)
            $table->text('ai_prompt')->nullable(); // AI prompt/question
            $table->text('user_response')->nullable(); // User's response
            $table->enum('response_type', ['text', 'voice'])->nullable(); // How user responded
            $table->text('ai_feedback')->nullable(); // AI feedback/explanation
            $table->unsignedInteger('interaction_order'); // Order of interactions
            $table->timestamps();

            // Indexes
            $table->index('session_id');
            $table->index('question_id');
            $table->index('step_number');
            $table->index('interaction_order');
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
