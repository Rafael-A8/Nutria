<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->text('content');
            $table->string('category');
            $table->vector('embedding', 1536);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_memories');
    }
};
