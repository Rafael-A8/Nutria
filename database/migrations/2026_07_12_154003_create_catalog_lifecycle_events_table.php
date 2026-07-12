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
        Schema::create('catalog_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->uuid('subject_public_id');
            $table->string('event_type', 40);
            $table->string('outcome', 32);
            $table->string('reason_code', 64);
            $table->text('reason')->nullable();
            $table->string('previous_state', 32)->nullable();
            $table->string('next_state', 32)->nullable();
            $table->json('eligibility_reasons')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_reference', 191);
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->uuid('idempotency_key')->nullable();
            $table->char('command_fingerprint', 64)->nullable();
            $table->uuid('correlation_id');
            $table->uuid('transaction_id');
            $table->timestampTz('created_at', 6);

            $table->unique('idempotency_key', 'catalog_lifecycle_events_root_idempotency_unique');
            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'cl_evt_subject_id_occurred_idx');
            $table->index(['subject_type', 'subject_public_id', 'occurred_at'], 'cl_evt_subject_public_occurred_idx');
            $table->index(['correlation_id', 'occurred_at'], 'cl_evt_correlation_occurred_idx');
            $table->index(['transaction_id', 'occurred_at'], 'cl_evt_transaction_occurred_idx');
            $table->index(['actor_reference', 'occurred_at'], 'cl_evt_actor_reference_occurred_idx');
            $table->index(['outcome', 'reason_code', 'occurred_at'], 'cl_evt_outcome_reason_occurred_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgreSqlChecks();
            $this->createPostgreSqlAppendOnlyProtection();
        }

        if ($driver === 'sqlite') {
            $this->createSqliteAppendOnlyProtection();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_catalog_lifecycle_events_block_update_delete ON catalog_lifecycle_events');
            DB::statement('DROP TRIGGER IF EXISTS trg_catalog_lifecycle_events_block_truncate ON catalog_lifecycle_events');
            DB::statement('DROP FUNCTION IF EXISTS fn_catalog_lifecycle_events_append_only()');
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS trg_catalog_lifecycle_events_block_update');
            DB::statement('DROP TRIGGER IF EXISTS trg_catalog_lifecycle_events_block_delete');
        }

        Schema::dropIfExists('catalog_lifecycle_events');
    }

    private function createPostgreSqlChecks(): void
    {
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_subject_type_check CHECK (subject_type IN ('source', 'reference', 'reference_version', 'alias', 'portion'))");
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_event_type_check CHECK (event_type IN ('create_source', 'edit_source', 'create_reference', 'create_draft', 'edit_draft', 'submit_for_review', 'return_to_draft', 'approve', 'reject', 'publish', 'activate', 'reactivate', 'deactivate', 'withdraw', 'archive', 'create_successor', 'change_authority'))");
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_outcome_check CHECK (outcome IN ('succeeded', 'no_op', 'invalid_transition', 'validation_failed', 'conflict'))");
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_reason_code_check CHECK (reason_code IN ('transition_applied', 'source_created', 'source_updated', 'reference_created', 'draft_created', 'draft_updated', 'successor_created', 'already_pending_review', 'already_approved', 'already_published', 'already_active', 'already_deactivated', 'already_withdrawn', 'already_archived', 'invalid_transition', 'content_frozen', 'terminal_rejected', 'terminal_withdrawn', 'terminal_archived', 'actor_required', 'reason_required', 'idempotency_key_invalid', 'subject_identifier_invalid', 'occurred_at_invalid', 'incomplete_content', 'parent_archived', 'reference_has_active_children', 'not_approved', 'not_published', 'nutrition_incomplete', 'primary_source_missing', 'primary_source_not_unique', 'source_missing', 'source_ineligible', 'source_prohibited', 'source_archived', 'source_record_key_missing', 'provenance_incomplete', 'source_scope_mismatch', 'source_already_used', 'concept_incompatible', 'alias_kind_invalid', 'alias_normalization_missing', 'alias_locale_missing', 'generic_alias_reference_mismatch', 'brand_alias_generic_reference_mismatch', 'active_alias_conflict', 'portion_evidence_invalid', 'portion_locale_missing', 'active_portion_conflict', 'self_approval_prohibited', 'superseded_predecessor', 'successor_exists', 'parent_mismatch', 'lineage_mismatch', 'not_lineage_head', 'number_conflict', 'active_version_conflict', 'concurrency_conflict', 'idempotency_key_reused', 'not_found', 'unauthorized', 'catalog_integrity_violation'))");
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_previous_state_check CHECK (previous_state IS NULL OR previous_state IN ('available', 'draft', 'pending_review', 'approved', 'rejected', 'published_inactive', 'active', 'deactivated', 'withdrawn', 'archived'))");
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_next_state_check CHECK (next_state IS NULL OR next_state IN ('available', 'draft', 'pending_review', 'approved', 'rejected', 'published_inactive', 'active', 'deactivated', 'withdrawn', 'archived'))");
        DB::statement('ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_root_pair_check CHECK ((idempotency_key IS NOT NULL AND command_fingerprint IS NOT NULL) OR (idempotency_key IS NULL AND command_fingerprint IS NULL))');
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_fingerprint_check CHECK (command_fingerprint IS NULL OR command_fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_actor_reference_check CHECK (btrim(actor_reference) <> '')");
        DB::statement('ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_subject_id_positive_check CHECK (subject_id > 0)');
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_no_op_states_check CHECK (outcome <> 'no_op' OR (previous_state IS NOT NULL AND next_state IS NOT NULL AND previous_state = next_state))");
        DB::statement("ALTER TABLE catalog_lifecycle_events ADD CONSTRAINT cl_evt_validation_eligibility_check CHECK (outcome <> 'validation_failed' OR eligibility_reasons IS NOT NULL)");
    }

    private function createPostgreSqlAppendOnlyProtection(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_catalog_lifecycle_events_append_only()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'UPDATE'
        AND OLD.actor_user_id IS NOT NULL
        AND NEW.actor_user_id IS NULL
        AND NEW.id IS NOT DISTINCT FROM OLD.id
        AND NEW.public_id IS NOT DISTINCT FROM OLD.public_id
        AND NEW.subject_type IS NOT DISTINCT FROM OLD.subject_type
        AND NEW.subject_id IS NOT DISTINCT FROM OLD.subject_id
        AND NEW.subject_public_id IS NOT DISTINCT FROM OLD.subject_public_id
        AND NEW.event_type IS NOT DISTINCT FROM OLD.event_type
        AND NEW.outcome IS NOT DISTINCT FROM OLD.outcome
        AND NEW.reason_code IS NOT DISTINCT FROM OLD.reason_code
        AND NEW.reason IS NOT DISTINCT FROM OLD.reason
        AND NEW.previous_state IS NOT DISTINCT FROM OLD.previous_state
        AND NEW.next_state IS NOT DISTINCT FROM OLD.next_state
        AND NEW.eligibility_reasons::text IS NOT DISTINCT FROM OLD.eligibility_reasons::text
        AND NEW.actor_reference IS NOT DISTINCT FROM OLD.actor_reference
        AND NEW.metadata::text IS NOT DISTINCT FROM OLD.metadata::text
        AND NEW.occurred_at IS NOT DISTINCT FROM OLD.occurred_at
        AND NEW.idempotency_key IS NOT DISTINCT FROM OLD.idempotency_key
        AND NEW.command_fingerprint IS NOT DISTINCT FROM OLD.command_fingerprint
        AND NEW.correlation_id IS NOT DISTINCT FROM OLD.correlation_id
        AND NEW.transaction_id IS NOT DISTINCT FROM OLD.transaction_id
        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
    THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION USING
        ERRCODE = 'N3401',
        MESSAGE = 'catalog lifecycle events are append-only';
END;
$$
SQL);
        DB::statement('CREATE TRIGGER trg_catalog_lifecycle_events_block_update_delete BEFORE UPDATE OR DELETE ON catalog_lifecycle_events FOR EACH ROW EXECUTE FUNCTION fn_catalog_lifecycle_events_append_only()');
        DB::statement('CREATE TRIGGER trg_catalog_lifecycle_events_block_truncate BEFORE TRUNCATE ON catalog_lifecycle_events FOR EACH STATEMENT EXECUTE FUNCTION fn_catalog_lifecycle_events_append_only()');
    }

    private function createSqliteAppendOnlyProtection(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_catalog_lifecycle_events_block_update
BEFORE UPDATE ON catalog_lifecycle_events
WHEN NOT (
    OLD.actor_user_id IS NOT NULL
    AND NEW.actor_user_id IS NULL
    AND NEW.id IS OLD.id
    AND NEW.public_id IS OLD.public_id
    AND NEW.subject_type IS OLD.subject_type
    AND NEW.subject_id IS OLD.subject_id
    AND NEW.subject_public_id IS OLD.subject_public_id
    AND NEW.event_type IS OLD.event_type
    AND NEW.outcome IS OLD.outcome
    AND NEW.reason_code IS OLD.reason_code
    AND NEW.reason IS OLD.reason
    AND NEW.previous_state IS OLD.previous_state
    AND NEW.next_state IS OLD.next_state
    AND NEW.eligibility_reasons IS OLD.eligibility_reasons
    AND NEW.actor_reference IS OLD.actor_reference
    AND NEW.metadata IS OLD.metadata
    AND NEW.occurred_at IS OLD.occurred_at
    AND NEW.idempotency_key IS OLD.idempotency_key
    AND NEW.command_fingerprint IS OLD.command_fingerprint
    AND NEW.correlation_id IS OLD.correlation_id
    AND NEW.transaction_id IS OLD.transaction_id
    AND NEW.created_at IS OLD.created_at
)
BEGIN
    SELECT RAISE(ABORT, 'catalog lifecycle events are append-only');
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_catalog_lifecycle_events_block_delete
BEFORE DELETE ON catalog_lifecycle_events
BEGIN
    SELECT RAISE(ABORT, 'catalog lifecycle events are append-only');
END
SQL);
    }
};
