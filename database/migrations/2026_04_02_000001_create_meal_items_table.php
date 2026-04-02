<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->integer('quantity_grams')->nullable();
            $table->integer('calories');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE meal_items ADD COLUMN IF NOT EXISTS embedding vector(1536)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE meal_items DROP COLUMN IF EXISTS embedding');
        Schema::dropIfExists('meal_items');
    }
};
