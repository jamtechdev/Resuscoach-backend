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
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // started_at + 45 minutes
            $table->decimal('score', 5, 2)->nullable(); // Final percentage score
            $table->unsignedTinyInteger('total_questions')->default(40);
            $table->unsignedTinyInteger('correct_count')->default(0);
            $table->enum('status', ['in_progress', 'completed', 'expired'])->default('in_progress');
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('status');
            $table->index('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
