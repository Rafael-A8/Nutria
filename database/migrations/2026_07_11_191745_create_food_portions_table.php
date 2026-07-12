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
        Schema::create('food_portions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('lineage_id');
            $table->foreignId('food_reference_id')->constrained('food_references')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignId('supersedes_food_portion_id')->nullable()->unique()->constrained('food_portions')->restrictOnDelete();
            $table->string('portion_key', 100);
            $table->string('display_label');
            $table->string('normalized_label', 191);
            $table->string('locale', 16);
            $table->string('portion_type', 32);
            $table->string('unit_code', 32);
            $table->decimal('unit_quantity', 12, 4);
            $table->decimal('gram_weight', 12, 4);
            $table->string('preparation_key', 64)->default('any');
            $table->string('size_label')->nullable();
            $table->foreignId('food_source_id')->nullable()->constrained('food_sources')->restrictOnDelete();
            $table->string('source_record_key', 191)->nullable();
            $table->json('provenance')->nullable();
            $table->string('review_status', 24)->default('draft');
            $this->addLifecycleColumns($table);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(6);

            $table->unique(['lineage_id', 'revision_number']);
            $table->index(['food_reference_id', 'locale', 'normalized_label']);
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE food_portions ADD CONSTRAINT food_portions_revision_number_positive_check CHECK (revision_number > 0)');
            DB::statement('ALTER TABLE food_portions ADD CONSTRAINT food_portions_unit_quantity_positive_check CHECK (unit_quantity > 0)');
            DB::statement('ALTER TABLE food_portions ADD CONSTRAINT food_portions_gram_weight_positive_check CHECK (gram_weight > 0)');
            DB::statement("ALTER TABLE food_portions ADD CONSTRAINT food_portions_review_status_check CHECK (review_status IN ('draft', 'pending_review', 'approved', 'rejected'))");
            DB::statement("ALTER TABLE food_portions ADD CONSTRAINT food_portions_activation_eligibility_check CHECK (activated_at IS NULL OR (review_status = 'approved' AND published_at IS NOT NULL))");
            DB::statement('ALTER TABLE food_portions ADD CONSTRAINT food_portions_deactivation_requires_activation_check CHECK (deactivated_at IS NULL OR activated_at IS NOT NULL)');
            DB::statement("ALTER TABLE food_portions ADD CONSTRAINT food_portions_publication_requires_approval_check CHECK (published_at IS NULL OR review_status = 'approved')");
            DB::statement('ALTER TABLE food_portions ADD CONSTRAINT food_portions_archived_not_active_check CHECK (archived_at IS NULL OR activated_at IS NULL OR deactivated_at IS NOT NULL OR withdrawn_at IS NOT NULL)');
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX food_portions_one_active_key_unique ON food_portions (food_reference_id, locale, portion_key, preparation_key) WHERE activated_at IS NOT NULL AND deactivated_at IS NULL AND withdrawn_at IS NULL AND archived_at IS NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_portions');
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
