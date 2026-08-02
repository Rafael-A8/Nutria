<?php

namespace App\Nutrition\Infrastructure\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\ApprovedCatalogImportApplyPlanLoader;
use App\Nutrition\Application\Catalog\Import\CatalogImportApplyPlanBuilder;
use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportGraphState;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportGraphInspection;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSnapshot;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportLifecycleIdempotencyInput;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedApprovedCatalogImportArtifacts;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleCommandFingerprint;
use App\Nutrition\Application\Catalog\NormalizeFoodText;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;

final class ApprovedCatalogImportGraphInspector
{
    public function __construct(
        private NormalizeFoodText $normalizeFoodText,
        private ReadOnlyCatalogImportPreflight $committedPreflight,
        private ReadOnlyCatalogImportApplyPlanPreflight $applyPlanPreflight,
        private CatalogImportApplyPlanBuilder $builder,
    ) {}

    public function inspect(LoadedApprovedCatalogImportArtifacts $artifacts): ApprovedCatalogImportGraphInspection
    {
        return $this->inspectGraph($artifacts, false);
    }

    public function inspectPostWrite(LoadedApprovedCatalogImportArtifacts $artifacts): ApprovedCatalogImportGraphInspection
    {
        return $this->inspectGraph($artifacts, true);
    }

    private function inspectGraph(
        LoadedApprovedCatalogImportArtifacts $artifacts,
        bool $requireExactFirstApplyCounts,
    ): ApprovedCatalogImportGraphInspection {
        [$normalizedNames, $preflightCandidates] = $this->preflightInputs($artifacts);
        $committedPreflight = $this->committedPreflight->inspect($preflightCandidates);
        $snapshot = $this->applyPlanPreflight->inspect(
            $artifacts->resolution->selectedEntries,
            $committedPreflight,
        );
        $expectedFingerprints = $this->graphFingerprints($artifacts->applyPlan);

        try {
            $current = $this->builder->build(
                manifest: $artifacts->manifest,
                resolution: $artifacts->resolution,
                approval: $artifacts->approval,
                snapshot: $snapshot,
                normalizedCanonicalNames: $normalizedNames,
            )->plan;
        } catch (LegacyNutritionApplyPlanException) {
            return new ApprovedCatalogImportGraphInspection(
                ApprovedCatalogImportGraphState::Conflict,
                $expectedFingerprints,
                $snapshot->fingerprint,
            );
        }

        if ($this->graphFingerprints($current) !== $expectedFingerprints) {
            return new ApprovedCatalogImportGraphInspection(
                ApprovedCatalogImportGraphState::Conflict,
                $expectedFingerprints,
                $snapshot->fingerprint,
            );
        }

        if ($this->isCompleteEntityGraph($current, $snapshot)) {
            $state = (
                ! $requireExactFirstApplyCounts
                || $this->hasExactFirstApplyCounts($snapshot)
            ) && $this->rootEventsAreExact($artifacts, $requireExactFirstApplyCounts)
                ? ApprovedCatalogImportGraphState::Exact
                : ApprovedCatalogImportGraphState::Conflict;

            return new ApprovedCatalogImportGraphInspection($state, $expectedFingerprints, $snapshot->fingerprint);
        }

        if (! $this->isFullyAbsentPlan($current)) {
            return new ApprovedCatalogImportGraphInspection(
                ApprovedCatalogImportGraphState::Conflict,
                $expectedFingerprints,
                $snapshot->fingerprint,
            );
        }

        $state = hash_equals(ApprovedCatalogImportApplyPlanLoader::APPROVED_PREFLIGHT_FINGERPRINT, $snapshot->fingerprint)
            ? ApprovedCatalogImportGraphState::AbsentAtApprovedSnapshot
            : ApprovedCatalogImportGraphState::Drift;

        return new ApprovedCatalogImportGraphInspection($state, $expectedFingerprints, $snapshot->fingerprint);
    }

    /**
     * @return array{array<string, string>, list<array<string, mixed>>}
     */
    private function preflightInputs(LoadedApprovedCatalogImportArtifacts $artifacts): array
    {
        $normalizedNames = [];
        $preflightCandidates = [];

        foreach ($artifacts->resolution->selectedEntries as $entry) {
            $normalizedName = $this->normalizeFoodText->normalize($entry['canonical_name'])->value;
            $normalizedNames[$entry['source_record_key']] = $normalizedName;
            $preflightCandidates[] = [
                'existing_reference_public_id' => $entry['existing_reference_public_id'],
                'is_generic' => $entry['is_generic'],
                'normalized_aliases' => array_column($entry['alias_decisions'], 'normalized_alias'),
                'normalized_canonical_name' => $normalizedName,
                'owner_user_id' => $entry['owner_user_id'],
                'owner_user_id_decision' => $entry['owner_user_id_decision'],
                'reference_target' => $entry['reference_target'],
                'reference_visibility' => $entry['reference_visibility'],
                'source_record_key' => $entry['source_record_key'],
                'stable_key' => $entry['stable_key'],
            ];
        }

        return [$normalizedNames, $preflightCandidates];
    }

    /** @param array<string, mixed> $plan @return array<string, string> */
    private function graphFingerprints(array $plan): array
    {
        $fingerprints = [];

        foreach ($plan['selected_candidate_plans'] ?? [] as $candidatePlan) {
            $fingerprints[$candidatePlan['source_record_key']] = $candidatePlan['graph_fingerprint'];
        }

        ksort($fingerprints);

        return $fingerprints;
    }

    /** @param array<string, mixed> $current */
    private function isFullyAbsentPlan(array $current): bool
    {
        if (($current['source_plan']['action'] ?? null) !== 'create') {
            return false;
        }

        foreach ($current['selected_candidate_plans'] ?? [] as $plan) {
            if (
                ($plan['graph_outcome'] ?? null) !== 'planned'
                || ($plan['reference_plan']['action'] ?? null) !== 'create'
                || ($plan['version_plan']['action'] ?? null) !== 'create'
                || ($plan['source_link_plan']['action'] ?? null) !== 'create'
            ) {
                return false;
            }

            foreach ($plan['alias_plans'] ?? [] as $aliasPlan) {
                if (! in_array($aliasPlan['action'] ?? null, ['create_lineage', 'excluded'], true)) {
                    return false;
                }
            }
        }

        return count($current['selected_candidate_plans'] ?? []) === 3;
    }

    /** @param array<string, mixed> $current */
    private function isCompleteEntityGraph(array $current, CatalogImportApplyPlanSnapshot $snapshot): bool
    {
        if (($current['source_plan']['action'] ?? null) !== 'unchanged') {
            return false;
        }

        foreach ($current['selected_candidate_plans'] ?? [] as $plan) {
            $referencePublicId = $plan['reference_plan']['semantic_entity']['public_id'];
            $versionPublicId = $plan['version_plan']['semantic_entity']['public_id'];

            if (
                ($plan['graph_outcome'] ?? null) !== 'no_op'
                || count($snapshot->versionsByReferencePublicId[$referencePublicId] ?? []) !== 1
                || count($snapshot->aliasesByReferencePublicId[$referencePublicId] ?? []) !== 1
                || count($snapshot->sourceLinksByVersionPublicId[$versionPublicId] ?? []) !== 1
            ) {
                return false;
            }
        }

        return count($current['selected_candidate_plans'] ?? []) === 3;
    }

    private function hasExactFirstApplyCounts(CatalogImportApplyPlanSnapshot $snapshot): bool
    {
        return $snapshot->catalogCounts === [
            'aliases' => 3,
            'reference_version_sources' => 3,
            'reference_versions' => 3,
            'references' => 3,
            'sources' => 1,
        ] && FoodPortion::query()->count() === 0;
    }

    private function rootEventsAreExact(
        LoadedApprovedCatalogImportArtifacts $artifacts,
        bool $requireExactFirstApplyCounts,
    ): bool {
        if ($requireExactFirstApplyCounts && CatalogLifecycleEvent::query()->count() !== 10) {
            return false;
        }

        $specifications = $this->eventSpecifications($artifacts->applyPlan);
        $subjectPublicIds = array_keys($specifications);
        $entityAudit = $this->entityAudit($specifications);

        if (count($entityAudit) !== 10) {
            return false;
        }

        $events = CatalogLifecycleEvent::query()
            ->whereIn('subject_public_id', $subjectPublicIds)
            ->orderBy('subject_public_id')
            ->orderBy('id')
            ->get();

        if ($events->count() !== 10) {
            return false;
        }

        $commonActorId = null;
        $commonActorReference = null;
        $commonReason = null;
        $commonOccurredAt = null;

        foreach ($events as $event) {
            $specification = $specifications[$event->subject_public_id] ?? null;
            $audit = $entityAudit[$event->subject_public_id] ?? null;

            if ($specification === null || $audit === null || $event->idempotency_key === null) {
                return false;
            }

            if ($commonActorId === null) {
                $commonActorId = $event->actor_user_id;
                $commonActorReference = $event->actor_reference;
                $commonReason = $event->reason;
                $commonOccurredAt = $event->occurred_at?->format('Y-m-d\TH:i:s.u\Z');
            }

            if (
                $event->actor_user_id === null
                || $event->actor_user_id !== $commonActorId
                || $event->actor_reference !== $commonActorReference
                || $event->reason !== $commonReason
                || $event->occurred_at?->format('Y-m-d\TH:i:s.u\Z') !== $commonOccurredAt
                || $audit['created_by_user_id'] !== $event->actor_user_id
                || $audit['id'] !== $event->subject_id
                || $event->subject_type !== $specification['subject_type']
                || $event->event_type !== $specification['operation']
                || $event->outcome !== CatalogLifecycleOutcome::Succeeded
                || $event->reason_code !== $specification['reason_code']
                || $event->previous_state !== null
                || $event->next_state !== $specification['next_state']
                || $event->eligibility_reasons !== null
                || $event->metadata !== null
            ) {
                return false;
            }

            $idempotencyKey = CatalogImportDeterministicIdentity::lifecycleIdempotencyKey(
                new CatalogImportLifecycleIdempotencyInput(
                    manifestChecksum: $artifacts->manifest->checksum,
                    subjectType: $specification['subject_type'],
                    subjectPublicId: $event->subject_public_id,
                    operation: $specification['operation'],
                    actorId: (string) $event->actor_user_id,
                    actorReference: $event->actor_reference,
                    reason: $event->reason,
                    occurredAt: $event->occurred_at->toDateTimeImmutable(),
                ),
            );

            $command = new CatalogLifecycleCommand(
                subjectType: $specification['subject_type'],
                subjectId: $event->subject_public_id,
                operation: $specification['operation'],
                actorId: (string) $event->actor_user_id,
                reason: $event->reason,
                idempotencyKey: $idempotencyKey,
                occurredAt: $event->occurred_at->toDateTimeImmutable(),
            );

            if (
                ! hash_equals($idempotencyKey, $event->idempotency_key)
                || $event->command_fingerprint === null
                || ! hash_equals(
                    CatalogLifecycleCommandFingerprint::forCommand($command, $event->actor_reference),
                    $event->command_fingerprint,
                )
            ) {
                return false;
            }
        }

        if ($commonActorId === null) {
            return false;
        }

        $versionIds = array_values(array_map(
            fn (array $audit): int => $audit['id'],
            array_filter(
                $entityAudit,
                fn (array $audit): bool => $audit['subject_type'] === CatalogLifecycleSubjectType::ReferenceVersion,
            ),
        ));

        return FoodReferenceVersionSource::query()
            ->whereIn('food_reference_version_id', $versionIds)
            ->where('created_by_user_id', $commonActorId)
            ->count() === 3;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, array{subject_type: CatalogLifecycleSubjectType, operation: CatalogLifecycleOperation, reason_code: CatalogLifecycleReason, next_state: CatalogLifecycleState}>
     */
    private function eventSpecifications(array $plan): array
    {
        $specifications = [];
        $this->addEventSpecification(
            $specifications,
            $plan['source_plan']['semantic_entity']['public_id'],
            CatalogLifecycleSubjectType::Source,
            CatalogLifecycleOperation::CreateSource,
            CatalogLifecycleReason::SourceCreated,
            CatalogLifecycleState::Available,
        );

        foreach ($plan['selected_candidate_plans'] as $candidatePlan) {
            $this->addEventSpecification(
                $specifications,
                $candidatePlan['reference_plan']['semantic_entity']['public_id'],
                CatalogLifecycleSubjectType::Reference,
                CatalogLifecycleOperation::CreateReference,
                CatalogLifecycleReason::ReferenceCreated,
                CatalogLifecycleState::Available,
            );
            $this->addEventSpecification(
                $specifications,
                $candidatePlan['version_plan']['semantic_entity']['public_id'],
                CatalogLifecycleSubjectType::ReferenceVersion,
                CatalogLifecycleOperation::CreateDraft,
                CatalogLifecycleReason::DraftCreated,
                CatalogLifecycleState::Draft,
            );

            foreach ($candidatePlan['alias_plans'] as $aliasPlan) {
                if (($aliasPlan['action'] ?? null) !== 'excluded') {
                    $this->addEventSpecification(
                        $specifications,
                        $aliasPlan['semantic_entity']['public_id'],
                        CatalogLifecycleSubjectType::Alias,
                        CatalogLifecycleOperation::CreateDraft,
                        CatalogLifecycleReason::DraftCreated,
                        CatalogLifecycleState::Draft,
                    );
                }
            }
        }

        ksort($specifications);

        return $specifications;
    }

    /** @param array<string, array<string, mixed>> $specifications */
    private function addEventSpecification(
        array &$specifications,
        string $publicId,
        CatalogLifecycleSubjectType $subjectType,
        CatalogLifecycleOperation $operation,
        CatalogLifecycleReason $reasonCode,
        CatalogLifecycleState $nextState,
    ): void {
        $specifications[$publicId] = [
            'subject_type' => $subjectType,
            'operation' => $operation,
            'reason_code' => $reasonCode,
            'next_state' => $nextState,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $specifications
     * @return array<string, array{id: int, created_by_user_id: int|null, subject_type: CatalogLifecycleSubjectType}>
     */
    private function entityAudit(array $specifications): array
    {
        $audit = [];
        $modelBySubject = [
            CatalogLifecycleSubjectType::Source->value => FoodSource::class,
            CatalogLifecycleSubjectType::Reference->value => FoodReference::class,
            CatalogLifecycleSubjectType::ReferenceVersion->value => FoodReferenceVersion::class,
            CatalogLifecycleSubjectType::Alias->value => FoodAlias::class,
        ];

        foreach ($modelBySubject as $subjectValue => $model) {
            $subjectType = CatalogLifecycleSubjectType::from($subjectValue);
            $publicIds = array_keys(array_filter(
                $specifications,
                fn (array $specification): bool => $specification['subject_type'] === $subjectType,
            ));

            foreach ($model::query()->select(['id', 'public_id', 'created_by_user_id'])->whereIn('public_id', $publicIds)->get() as $row) {
                $audit[$row->public_id] = [
                    'id' => (int) $row->id,
                    'created_by_user_id' => $row->created_by_user_id,
                    'subject_type' => $subjectType,
                ];
            }
        }

        return $audit;
    }
}
