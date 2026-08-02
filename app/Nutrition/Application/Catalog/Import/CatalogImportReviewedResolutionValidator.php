<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;

final class CatalogImportReviewedResolutionValidator
{
    private const EDITABLE_ENTRY_KEYS = [
        'alias_decisions',
        'catalog_classification',
        'editorial_notes',
        'existing_reference_public_id',
        'is_generic',
        'owner_user_id',
        'owner_user_id_decision',
        'preflight_conflict_decisions',
        'preparation_decision',
        'reference_target',
        'reference_visibility',
        'selected_for_apply',
        'stable_key',
        'version_locale',
    ];

    public function __construct(
        private CatalogImportResolutionDocumentValidator $resolutionValidator,
        private CatalogImportReviewEligibilityValidator $eligibilityValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $baseline
     * @return array{eligible_count: int, selected_entries: list<array<string, mixed>>}
     */
    public function validate(
        array $document,
        array $baseline,
        LegacyNutritionReviewManifest $manifest,
    ): array {
        try {
            $this->resolutionValidator->validate($document, $manifest->checksum);
        } catch (\Throwable $exception) {
            throw new LegacyNutritionApplyPlanException(
                'invalid_resolution',
                $exception->getMessage(),
                $exception,
            );
        }

        $this->assertExactKeys($document, $baseline, 'resolution document');
        $this->assertExactValue($document['candidate_manifest'], $baseline['candidate_manifest'], 'manifest binding');
        $this->assertExactEntrySet($document['review_entries'], $baseline['review_entries']);

        $selectedEntries = [];
        $eligibleCount = 0;

        foreach ($document['review_entries'] as $entry) {
            $eligibility = $this->eligibilityValidator->evaluate($entry);

            if ($eligibility['eligible']) {
                $eligibleCount++;
            }

            if ($eligibility['selected_for_apply']) {
                if (! $eligibility['eligible']) {
                    throw new LegacyNutritionApplyPlanException(
                        'selected_candidate_ineligible',
                        "Selected candidate {$entry['source_record_key']} is ineligible: ".implode(', ', $eligibility['reasons']).'.',
                    );
                }

                $selectedEntries[] = $entry;
            }
        }

        $expectedSummary = [
            'approved_apply_plan_records' => 0,
            'resolved_candidate_identities' => $eligibleCount,
            'review_entries' => 106,
            'selected_candidates' => count($selectedEntries),
            'unresolved_candidate_identities' => 106 - $eligibleCount,
        ];

        if ($document['summary'] !== $expectedSummary) {
            throw new LegacyNutritionApplyPlanException(
                'invalid_resolution',
                'The editorial resolution summary does not match its decisions.',
            );
        }

        usort($selectedEntries, fn (array $left, array $right): int => strcmp(
            $left['source_record_key'],
            $right['source_record_key'],
        ));

        return ['eligible_count' => $eligibleCount, 'selected_entries' => $selectedEntries];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $baselineEntries
     */
    private function assertExactEntrySet(array $entries, array $baselineEntries): void
    {
        $baselineByKey = [];

        foreach ($baselineEntries as $baselineEntry) {
            $baselineByKey[$baselineEntry['source_record_key']] = $baselineEntry;
        }

        if (count($entries) !== count($baselineByKey)) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', 'The reviewed candidate set is incomplete.');
        }

        foreach ($entries as $entry) {
            $key = $entry['source_record_key'] ?? null;

            if (! is_string($key) || ! isset($baselineByKey[$key])) {
                throw new LegacyNutritionApplyPlanException('invalid_resolution', 'The resolution contains an unexpected candidate.');
            }

            $baselineEntry = $baselineByKey[$key];
            $this->assertExactKeys($entry, $baselineEntry, "candidate {$key}");

            foreach ($baselineEntry as $field => $baselineValue) {
                if (! in_array($field, self::EDITABLE_ENTRY_KEYS, true)) {
                    $this->assertExactValue($entry[$field], $baselineValue, "candidate fact {$key}.{$field}");
                }
            }

            $this->assertDecisionShapes($entry, $baselineEntry, $key);
            unset($baselineByKey[$key]);
        }

        if ($baselineByKey !== []) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', 'The resolution omits approved candidates.');
        }
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $baseline */
    private function assertDecisionShapes(array $entry, array $baseline, string $key): void
    {
        $this->assertExactKeys($entry['preparation_decision'], $baseline['preparation_decision'], "preparation {$key}");

        if (! is_array($entry['alias_decisions']) || count($entry['alias_decisions']) !== count($baseline['alias_decisions'])) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', "Alias decisions changed candidate facts for {$key}.");
        }

        foreach ($entry['alias_decisions'] as $index => $alias) {
            $baselineAlias = $baseline['alias_decisions'][$index] ?? null;

            if (! is_array($alias) || ! is_array($baselineAlias)) {
                throw new LegacyNutritionApplyPlanException('invalid_resolution', "Alias decisions are malformed for {$key}.");
            }

            $this->assertExactKeys($alias, $baselineAlias, "alias {$key}");

            foreach ($baselineAlias as $field => $value) {
                if (! in_array($field, ['status', 'alias_kind'], true)) {
                    $this->assertExactValue($alias[$field], $value, "alias fact {$key}.{$field}");
                }
            }
        }

        if (! is_array($entry['preflight_conflict_decisions']) || count($entry['preflight_conflict_decisions']) !== count($baseline['preflight_conflict_decisions'])) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', "Preflight conflict evidence changed for {$key}.");
        }

        foreach ($entry['preflight_conflict_decisions'] as $index => $decision) {
            $baselineDecision = $baseline['preflight_conflict_decisions'][$index] ?? null;

            if (! is_array($decision) || ! is_array($baselineDecision)) {
                throw new LegacyNutritionApplyPlanException('invalid_resolution', "Preflight conflict decisions are malformed for {$key}.");
            }

            $this->assertExactKeys($decision, $baselineDecision, "preflight conflict {$key}");

            foreach ($baselineDecision as $field => $value) {
                if (! in_array($field, ['editorial_resolution', 'resolution_status'], true)) {
                    $this->assertExactValue($decision[$field], $value, "preflight conflict fact {$key}.{$field}");
                }
            }
        }
    }

    /** @param array<string, mixed> $actual @param array<string, mixed> $expected */
    private function assertExactKeys(array $actual, array $expected, string $context): void
    {
        $actualKeys = array_keys($actual);
        $expectedKeys = array_keys($expected);
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', "Unknown or missing keys in {$context}.");
        }
    }

    private function assertExactValue(mixed $actual, mixed $expected, string $context): void
    {
        if ($actual !== $expected) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', "Approved candidate evidence changed in {$context}.");
        }
    }
}
