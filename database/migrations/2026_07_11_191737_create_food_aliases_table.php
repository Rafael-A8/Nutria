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
        Schema::create('food_aliases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('lineage_id');
            $table->foreignId('food_reference_id')->constrained('food_references')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignId('supersedes_food_alias_id')->nullable()->unique()->constrained('food_aliases')->restrictOnDelete();
            $table->string('display_alias');
            $table->string('normalized_alias', 191);
            $table->string('locale', 16);
            $table->string('alias_kind', 16);
            $table->foreignId('food_source_id')->nullable()->constrained('food_sources')->restrictOnDelete();
            $table->string('source_record_key', 191)->nullable();
            $table->json('provenance')->nullable();
            $table->string('review_status', 24)->default('draft');
            $this->addLifecycleColumns($table);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(6);

            $table->unique(['lineage_id', 'revision_number']);
            $table->index(['locale', 'normalized_alias', 'food_reference_id']);
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE food_aliases ADD CONSTRAINT food_aliases_revision_number_positive_check CHECK (revision_number > 0)');
            DB::statement("ALTER TABLE food_aliases ADD CONSTRAINT food_aliases_review_status_check CHECK (review_status IN ('draft', 'pending_review', 'approved', 'rejected'))");
            DB::statement("ALTER TABLE food_aliases ADD CONSTRAINT food_aliases_activation_eligibility_check CHECK (activated_at IS NULL OR (review_status = 'approved' AND published_at IS NOT NULL))");
            DB::statement('ALTER TABLE food_aliases ADD CONSTRAINT food_aliases_deactivation_requires_activation_check CHECK (deactivated_at IS NULL OR activated_at IS NOT NULL)');
            DB::statement("ALTER TABLE food_aliases ADD CONSTRAINT food_aliases_publication_requires_approval_check CHECK (published_at IS NULL OR review_status = 'approved')");
            DB::statement('ALTER TABLE food_aliases ADD CONSTRAINT food_aliases_archived_not_active_check CHECK (archived_at IS NULL OR activated_at IS NULL OR deactivated_at IS NOT NULL OR withdrawn_at IS NOT NULL)');
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX food_aliases_one_active_key_unique ON food_aliases (food_reference_id, locale, normalized_alias) WHERE activated_at IS NOT NULL AND deactivated_at IS NULL AND withdrawn_at IS NULL AND archived_at IS NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_aliases');
    }

    private function addLifecycleColumns(Blueprint $table): void
    {
        $table->timestampTz('submitted_at', 6)->nullable();
        $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestampTz('reviewed_at', 6)->nullable();
        $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->text('review_reason')->nullable();
        $table->timestampTz('published_at', 6)->nullable();
        $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestampTz('activated_at', 6)->nullable();
        $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestampTz('deactivated_at', 6)->nullable();
        $table->foreignId('deactivated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->text('deactivation_reason')->nullable();
        $table->timestampTz('withdrawn_at', 6)->nullable();
        $table->foreignId('withdrawn_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->text('withdrawal_reason')->nullable();
        $table->timestampTz('archived_at', 6)->nullable();
        $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->text('archive_reason')->nullable();
    }
};
