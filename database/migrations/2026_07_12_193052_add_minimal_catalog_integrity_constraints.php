<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE food_aliases ADD CONSTRAINT food_aliases_alias_kind_check CHECK (alias_kind IN ('common', 'generic', 'regional', 'brand'))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_catalog_block_physical_delete()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = 'N3402',
        MESSAGE = 'physical deletion is prohibited';
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_food_sources_guard_identity_and_used_content()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.public_id IS DISTINCT FROM OLD.public_id
        OR NEW.visibility IS DISTINCT FROM OLD.visibility
        OR NEW.owner_user_id IS DISTINCT FROM OLD.owner_user_id
        OR NEW.kind IS DISTINCT FROM OLD.kind
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
        OR (
            NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
            AND NOT (OLD.created_by_user_id IS NOT NULL AND NEW.created_by_user_id IS NULL)
        )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'catalog identity is immutable';
    END IF;

    IF (
        NEW.title IS DISTINCT FROM OLD.title
        OR NEW.publisher IS DISTINCT FROM OLD.publisher
        OR NEW.edition IS DISTINCT FROM OLD.edition
        OR NEW.source_uri IS DISTINCT FROM OLD.source_uri
        OR NEW.citation IS DISTINCT FROM OLD.citation
        OR NEW.license IS DISTINCT FROM OLD.license
        OR NEW.checksum_algorithm IS DISTINCT FROM OLD.checksum_algorithm
        OR NEW.checksum IS DISTINCT FROM OLD.checksum
        OR NEW.retrieved_at IS DISTINCT FROM OLD.retrieved_at
        OR NEW.metadata::text IS DISTINCT FROM OLD.metadata::text
    ) AND (
        EXISTS (SELECT 1 FROM food_reference_version_sources WHERE food_source_id = OLD.id)
        OR EXISTS (SELECT 1 FROM food_aliases WHERE food_source_id = OLD.id)
        OR EXISTS (SELECT 1 FROM food_portions WHERE food_source_id = OLD.id)
    )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'source evidence is immutable after use';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_food_references_guard_identity()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.public_id IS DISTINCT FROM OLD.public_id
        OR NEW.stable_key IS DISTINCT FROM OLD.stable_key
        OR NEW.visibility IS DISTINCT FROM OLD.visibility
        OR NEW.owner_user_id IS DISTINCT FROM OLD.owner_user_id
        OR NEW.is_generic IS DISTINCT FROM OLD.is_generic
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
        OR (
            NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
            AND NOT (OLD.created_by_user_id IS NOT NULL AND NEW.created_by_user_id IS NULL)
        )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'catalog identity is immutable';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_food_reference_versions_guard_identity_and_frozen_content()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.public_id IS DISTINCT FROM OLD.public_id
        OR NEW.food_reference_id IS DISTINCT FROM OLD.food_reference_id
        OR NEW.version_number IS DISTINCT FROM OLD.version_number
        OR NEW.supersedes_food_reference_version_id IS DISTINCT FROM OLD.supersedes_food_reference_version_id
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
        OR (
            NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
            AND NOT (OLD.created_by_user_id IS NOT NULL AND NEW.created_by_user_id IS NULL)
        )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'catalog identity is immutable';
    END IF;

    IF (
        OLD.review_status IN ('approved', 'rejected')
        OR OLD.published_at IS NOT NULL
        OR OLD.withdrawn_at IS NOT NULL
        OR OLD.archived_at IS NOT NULL
    ) AND (
        NEW.canonical_name IS DISTINCT FROM OLD.canonical_name
        OR NEW.normalized_canonical_name IS DISTINCT FROM OLD.normalized_canonical_name
        OR NEW.locale IS DISTINCT FROM OLD.locale
        OR NEW.classification IS DISTINCT FROM OLD.classification
        OR NEW.preparation_key IS DISTINCT FROM OLD.preparation_key
        OR NEW.energy_basis_grams IS DISTINCT FROM OLD.energy_basis_grams
        OR NEW.energy_kcal IS DISTINCT FROM OLD.energy_kcal
        OR NEW.nutrient_values::text IS DISTINCT FROM OLD.nutrient_values::text
        OR NEW.provenance::text IS DISTINCT FROM OLD.provenance::text
    )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'catalog content is immutable after acceptance';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_food_aliases_guard_identity_and_frozen_content()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.public_id IS DISTINCT FROM OLD.public_id
        OR NEW.lineage_id IS DISTINCT FROM OLD.lineage_id
        OR NEW.food_reference_id IS DISTINCT FROM OLD.food_reference_id
        OR NEW.revision_number IS DISTINCT FROM OLD.revision_number
        OR NEW.supersedes_food_alias_id IS DISTINCT FROM OLD.supersedes_food_alias_id
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
        OR (
            NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
            AND NOT (OLD.created_by_user_id IS NOT NULL AND NEW.created_by_user_id IS NULL)
        )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'catalog identity is immutable';
    END IF;

    IF (
        OLD.review_status IN ('approved', 'rejected')
        OR OLD.published_at IS NOT NULL
        OR OLD.withdrawn_at IS NOT NULL
        OR OLD.archived_at IS NOT NULL
    ) AND (
        NEW.display_alias IS DISTINCT FROM OLD.display_alias
        OR NEW.normalized_alias IS DISTINCT FROM OLD.normalized_alias
        OR NEW.locale IS DISTINCT FROM OLD.locale
        OR NEW.alias_kind IS DISTINCT FROM OLD.alias_kind
        OR NEW.food_source_id IS DISTINCT FROM OLD.food_source_id
        OR NEW.source_record_key IS DISTINCT FROM OLD.source_record_key
        OR NEW.provenance::text IS DISTINCT FROM OLD.provenance::text
    )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'catalog content is immutable after acceptance';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_food_portions_guard_identity_and_frozen_content()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.public_id IS DISTINCT FROM OLD.public_id
        OR NEW.lineage_id IS DISTINCT FROM OLD.lineage_id
        OR NEW.food_reference_id IS DISTINCT FROM OLD.food_reference_id
        OR NEW.revision_number IS DISTINCT FROM OLD.revision_number
        OR NEW.supersedes_food_portion_id IS DISTINCT FROM OLD.supersedes_food_portion_id
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
        OR (
            NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
            AND NOT (OLD.created_by_user_id IS NOT NULL AND NEW.created_by_user_id IS NULL)
        )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'catalog identity is immutable';
    END IF;

    IF (
        OLD.review_status IN ('approved', 'rejected')
        OR OLD.published_at IS NOT NULL
        OR OLD.withdrawn_at IS NOT NULL
        OR OLD.archived_at IS NOT NULL
    ) AND (
        NEW.portion_key IS DISTINCT FROM OLD.portion_key
        OR NEW.display_label IS DISTINCT FROM OLD.display_label
        OR NEW.normalized_label IS DISTINCT FROM OLD.normalized_label
        OR NEW.locale IS DISTINCT FROM OLD.locale
        OR NEW.portion_type IS DISTINCT FROM OLD.portion_type
        OR NEW.unit_code IS DISTINCT FROM OLD.unit_code
        OR NEW.unit_quantity IS DISTINCT FROM OLD.unit_quantity
        OR NEW.gram_weight IS DISTINCT FROM OLD.gram_weight
        OR NEW.preparation_key IS DISTINCT FROM OLD.preparation_key
        OR NEW.size_label IS DISTINCT FROM OLD.size_label
        OR NEW.food_source_id IS DISTINCT FROM OLD.food_source_id
        OR NEW.source_record_key IS DISTINCT FROM OLD.source_record_key
        OR NEW.provenance::text IS DISTINCT FROM OLD.provenance::text
    )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'catalog content is immutable after acceptance';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_food_reference_version_sources_guard_frozen_parent()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    parent_is_frozen boolean;
    actor_nullification_only boolean := false;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.id IS DISTINCT FROM OLD.id
            OR NEW.food_reference_version_id IS DISTINCT FROM OLD.food_reference_version_id
            OR NEW.food_source_id IS DISTINCT FROM OLD.food_source_id
            OR NEW.created_at IS DISTINCT FROM OLD.created_at
            OR (
                NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                AND NOT (OLD.created_by_user_id IS NOT NULL AND NEW.created_by_user_id IS NULL)
            )
        THEN
            RAISE EXCEPTION USING
                ERRCODE = 'N3402',
                MESSAGE = 'catalog identity is immutable';
        END IF;

        actor_nullification_only := OLD.created_by_user_id IS NOT NULL
            AND NEW.created_by_user_id IS NULL
            AND NEW.role IS NOT DISTINCT FROM OLD.role
            AND NEW.source_record_key IS NOT DISTINCT FROM OLD.source_record_key
            AND NEW.evidence_metadata::text IS NOT DISTINCT FROM OLD.evidence_metadata::text
            AND NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at;
    END IF;

    SELECT review_status IN ('approved', 'rejected')
        OR published_at IS NOT NULL
        OR withdrawn_at IS NOT NULL
        OR archived_at IS NOT NULL
    INTO parent_is_frozen
    FROM food_reference_versions
    WHERE id = CASE WHEN TG_OP = 'INSERT' THEN NEW.food_reference_version_id ELSE OLD.food_reference_version_id END;

    IF COALESCE(parent_is_frozen, false) AND NOT actor_nullification_only THEN
        RAISE EXCEPTION USING
            ERRCODE = 'N3402',
            MESSAGE = 'source evidence is immutable after acceptance';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::statement('CREATE TRIGGER trg_food_sources_guard_update BEFORE UPDATE ON food_sources FOR EACH ROW EXECUTE FUNCTION fn_food_sources_guard_identity_and_used_content()');
        DB::statement('CREATE TRIGGER trg_food_references_guard_update BEFORE UPDATE ON food_references FOR EACH ROW EXECUTE FUNCTION fn_food_references_guard_identity()');
        DB::statement('CREATE TRIGGER trg_food_reference_versions_guard_update BEFORE UPDATE ON food_reference_versions FOR EACH ROW EXECUTE FUNCTION fn_food_reference_versions_guard_identity_and_frozen_content()');
        DB::statement('CREATE TRIGGER trg_food_aliases_guard_update BEFORE UPDATE ON food_aliases FOR EACH ROW EXECUTE FUNCTION fn_food_aliases_guard_identity_and_frozen_content()');
        DB::statement('CREATE TRIGGER trg_food_portions_guard_update BEFORE UPDATE ON food_portions FOR EACH ROW EXECUTE FUNCTION fn_food_portions_guard_identity_and_frozen_content()');
        DB::statement('CREATE TRIGGER trg_food_reference_version_sources_guard_mutation BEFORE INSERT OR UPDATE OR DELETE ON food_reference_version_sources FOR EACH ROW EXECUTE FUNCTION fn_food_reference_version_sources_guard_frozen_parent()');

        foreach (['food_sources', 'food_references', 'food_reference_versions', 'food_aliases', 'food_portions'] as $table) {
            DB::statement("CREATE TRIGGER trg_{$table}_block_delete BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION fn_catalog_block_physical_delete()");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['food_sources', 'food_references', 'food_reference_versions', 'food_aliases', 'food_portions'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS trg_{$table}_block_delete ON {$table}");
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_food_sources_guard_update ON food_sources');
        DB::statement('DROP TRIGGER IF EXISTS trg_food_references_guard_update ON food_references');
        DB::statement('DROP TRIGGER IF EXISTS trg_food_reference_versions_guard_update ON food_reference_versions');
        DB::statement('DROP TRIGGER IF EXISTS trg_food_aliases_guard_update ON food_aliases');
        DB::statement('DROP TRIGGER IF EXISTS trg_food_portions_guard_update ON food_portions');
        DB::statement('DROP TRIGGER IF EXISTS trg_food_reference_version_sources_guard_mutation ON food_reference_version_sources');
        DB::statement('ALTER TABLE food_aliases DROP CONSTRAINT IF EXISTS food_aliases_alias_kind_check');

        DB::statement('DROP FUNCTION IF EXISTS fn_food_reference_version_sources_guard_frozen_parent()');
        DB::statement('DROP FUNCTION IF EXISTS fn_food_portions_guard_identity_and_frozen_content()');
        DB::statement('DROP FUNCTION IF EXISTS fn_food_aliases_guard_identity_and_frozen_content()');
        DB::statement('DROP FUNCTION IF EXISTS fn_food_reference_versions_guard_identity_and_frozen_content()');
        DB::statement('DROP FUNCTION IF EXISTS fn_food_references_guard_identity()');
        DB::statement('DROP FUNCTION IF EXISTS fn_food_sources_guard_identity_and_used_content()');
        DB::statement('DROP FUNCTION IF EXISTS fn_catalog_block_physical_delete()');
    }
};
