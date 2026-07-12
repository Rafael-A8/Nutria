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
        Schema::create('food_reference_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('food_reference_id')->constrained('food_references')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('canonical_name');
            $table->string('normalized_canonical_name', 191);
            $table->string('locale', 16);
            $table->string('classification', 64);
            $table->string('preparation_key', 64)->nullable();
            $table->decimal('energy_basis_grams', 12, 4)->nullable();
            $table->decimal('energy_kcal', 12, 4)->nullable();
            $table->json('nutrient_values')->nullable();
            $table->json('provenance')->nullable();
            $table->string('review_status', 24)->default('draft');
            $this->addLifecycleColumns($table);
            $table->foreignId('supersedes_food_reference_version_id')->nullable()->unique()->constrained('food_reference_versions')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(6);

            $table->unique(['food_reference_id', 'version_number']);
            $table->index(['locale', 'normalized_canonical_name', 'food_reference_id']);
            $table->index(['food_reference_id', 'review_status', 'activated_at', 'deactivated_at', 'withdrawn_at']);
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE food_reference_versions ADD CONSTRAINT food_reference_versions_version_number_positive_check CHECK (version_number > 0)');
            DB::statement("ALTER TABLE food_reference_versions ADD CONSTRAINT food_reference_versions_review_status_check CHECK (review_status IN ('draft', 'pending_review', 'approved', 'rejected'))");
            DB::statement('ALTER TABLE food_reference_versions ADD CONSTRAINT food_reference_versions_energy_basis_positive_check CHECK (energy_basis_grams IS NULL OR energy_basis_grams > 0)');
            DB::statement('ALTER TABLE food_reference_versions ADD CONSTRAINT food_reference_versions_energy_kcal_positive_check CHECK (energy_kcal IS NULL OR energy_kcal > 0)');
            DB::statement("ALTER TABLE food_reference_versions ADD CONSTRAINT food_reference_versions_activation_eligibility_check CHECK (activated_at IS NULL OR (review_status = 'approved' AND published_at IS NOT NULL AND energy_basis_grams IS NOT NULL AND energy_basis_grams > 0 AND energy_kcal IS NOT NULL AND energy_kcal > 0))");
            DB::statement('ALTER TABLE food_reference_versions ADD CONSTRAINT food_reference_versions_deactivation_requires_activation_check CHECK (deactivated_at IS NULL OR activated_at IS NOT NULL)');
            DB::statement("ALTER TABLE food_reference_versions ADD CONSTRAINT food_reference_versions_publication_requires_approval_check CHECK (published_at IS NULL OR review_status = 'approved')");
            DB::statement('ALTER TABLE food_reference_versions ADD CONSTRAINT food_reference_versions_archived_not_active_check CHECK (archived_at IS NULL OR activated_at IS NULL OR deactivated_at IS NOT NULL OR withdrawn_at IS NOT NULL)');
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX food_reference_versions_one_active_unique ON food_reference_versions (food_reference_id) WHERE activated_at IS NOT NULL AND deactivated_at IS NULL AND withdrawn_at IS NULL AND archived_at IS NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_reference_versions');
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
