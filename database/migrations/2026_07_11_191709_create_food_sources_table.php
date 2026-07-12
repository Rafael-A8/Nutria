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
        Schema::create('food_sources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('visibility', 16);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('authority_status', 24)->default('prohibited');
            $table->string('title');
            $table->string('publisher')->nullable();
            $table->string('edition')->nullable();
            $table->text('source_uri')->nullable();
            $table->text('citation')->nullable();
            $table->string('license')->nullable();
            $table->string('checksum_algorithm', 32)->nullable();
            $table->string('checksum', 191)->nullable();
            $table->timestampTz('retrieved_at', 6)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('archived_at', 6)->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('archive_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(6);

            $table->index(['kind', 'authority_status', 'archived_at']);
            $table->index(['visibility', 'owner_user_id', 'archived_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE food_sources ADD CONSTRAINT food_sources_visibility_check CHECK (visibility IN ('global', 'private'))");
            DB::statement("ALTER TABLE food_sources ADD CONSTRAINT food_sources_owner_scope_check CHECK ((visibility = 'global' AND owner_user_id IS NULL) OR (visibility = 'private' AND owner_user_id IS NOT NULL))");
            DB::statement("ALTER TABLE food_sources ADD CONSTRAINT food_sources_kind_check CHECK (kind IN ('curated_dataset', 'scientific_publication', 'manufacturer_label', 'user_product_label', 'legacy_config', 'app_generated_estimate'))");
            DB::statement("ALTER TABLE food_sources ADD CONSTRAINT food_sources_authority_status_check CHECK (authority_status IN ('eligible', 'untrusted', 'prohibited'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_sources');
    }
};
