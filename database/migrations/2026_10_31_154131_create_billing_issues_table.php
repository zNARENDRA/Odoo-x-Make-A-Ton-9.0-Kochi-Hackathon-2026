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
        Schema::create('billing_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_key')->unique();
            $table->string('kind');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('title');
            $table->text('detail');
            $table->string('status')->default('open')->index();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_issues');
    }
};
