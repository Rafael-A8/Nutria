<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('food_references', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('stable_key', 191);
            $table->string('visibility', 16);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('is_generic')->default(false);
            $table->timestampTz('archived_at', 6)->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('archive_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(6);

            $table->index(['visibility', 'owner_user_id', 'archived_at']);
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE food_references ADD CONSTRAINT food_references_visibility_check CHECK (visibility IN ('global', 'private'))");
            DB::statement("ALTER TABLE food_references ADD CONSTRAINT food_references_owner_scope_check CHECK ((visibility = 'global' AND owner_user_id IS NULL) OR (visibility = 'private' AND owner_user_id IS NOT NULL))");
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX food_references_global_stable_key_unique ON food_references (stable_key) WHERE visibility = 'global' AND owner_user_id IS NULL");
            DB::statement("CREATE UNIQUE INDEX food_references_private_stable_key_unique ON food_references (owner_user_id, stable_key) WHERE visibility = 'private' AND owner_user_id IS NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_references');
    }
};
