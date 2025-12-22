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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->text('stem'); // Question text/vignette
            $table->text('option_a');
            $table->text('option_b');
            $table->text('option_c');
            $table->text('option_d');
            $table->text('option_e');
            $table->enum('correct_option', ['A', 'B', 'C', 'D', 'E']);
            $table->text('explanation');
            $table->string('topic'); // Main topic (e.g., "Cardiology")
            $table->string('subtopic')->nullable(); // Sub-topic (e.g., "ACS")
            $table->text('guideline_excerpt')->nullable(); // Text from official guideline
            $table->string('guideline_source')->nullable(); // Name of guideline (e.g., "NICE CG95")
            $table->enum('difficulty', ['Easy', 'Medium', 'Hard'])->default('Medium');
            $table->boolean('is_active')->default(true); // Whether question is available for exams
            $table->timestamps();

            // Indexes for filtering
            $table->index('topic');
            $table->index('subtopic');
            $table->index('difficulty');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
