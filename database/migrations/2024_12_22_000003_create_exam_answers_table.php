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
        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('exam_attempts')->onDelete('cascade');
            $table->foreignId('question_id')->constrained()->onDelete('cascade');
            $table->unsignedTinyInteger('question_order'); // Position in exam (1-40)
            $table->enum('selected_option', ['A', 'B', 'C', 'D', 'E'])->nullable();
            $table->boolean('is_correct')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('attempt_id');
            $table->index('question_id');
            $table->index('question_order');
            $table->unique(['attempt_id', 'question_id']); // One answer per question per attempt
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
    }
};
