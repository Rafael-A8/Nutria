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
        Schema::create('food_reference_version_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('food_reference_version_id')->constrained('food_reference_versions')->restrictOnDelete();
            $table->foreignId('food_source_id')->constrained('food_sources')->restrictOnDelete();
            $table->string('role', 16);
            $table->string('source_record_key', 191)->nullable();
            $table->json('evidence_metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(6);

            $table->unique(['food_reference_version_id', 'food_source_id']);
            $table->index(['food_source_id', 'food_reference_version_id']);
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE food_reference_version_sources ADD CONSTRAINT food_reference_version_sources_role_check CHECK (role IN ('primary', 'supporting'))");
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX food_reference_version_sources_one_primary_unique ON food_reference_version_sources (food_reference_version_id) WHERE role = 'primary'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_reference_version_sources');
    }
};
