<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->integer('calories');
            $table->timestamp('consumed_at');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE meals ADD COLUMN IF NOT EXISTS embedding vector(1536)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE meals DROP COLUMN IF EXISTS embedding');
        Schema::dropIfExists('meals');
    }
};
