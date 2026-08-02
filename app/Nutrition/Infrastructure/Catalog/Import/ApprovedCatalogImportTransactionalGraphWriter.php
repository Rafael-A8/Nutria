<?php

namespace App\Nutrition\Infrastructure\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportExecutionInput;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportLifecycleIdempotencyInput;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedApprovedCatalogImportArtifacts;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodAliasLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodSourceLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodAliasLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceVersionLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodSourceLifecycleSnapshot;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class ApprovedCatalogImportTransactionalGraphWriter
{
    public function __construct(
        private FoodSourceLifecyclePolicy $sourcePolicy,
        private FoodReferenceLifecyclePolicy $referencePolicy,
        private FoodReferenceVersionLifecyclePolicy $versionPolicy,
        private FoodAliasLifecyclePolicy $aliasPolicy,
        private CatalogLifecycleRootEventFactory $rootEventFactory,
        private EloquentCatalogLifecycleEventStore $eventStore,
    ) {}

    public function write(
        LoadedApprovedCatalogImportArtifacts $artifacts,
        ApprovedCatalogImportExecutionInput $input,
    ): void {
        $candidatePlans = $artifacts->applyPlan['selected_candidate_plans'];
        usort($candidatePlans, fn (array $left, array $right): int => strcmp(
            $left['reference_plan']['semantic_entity']['public_id'],
            $right['reference_plan']['semantic_entity']['public_id'],
        ));
        $creationDecisions = $this->creationDecisions($artifacts, $input, $candidatePlans);
        $context = new CatalogLifecycleExecutionContext($input->actorId, $input->actorReference);
        $sourceSemantic = $artifacts->applyPlan['source_plan']['semantic_entity'];
        $source = FoodSource::query()->create([
            'public_id' => $sourceSemantic['public_id'],
            'visibility' => $sourceSemantic['visibility'],
            'owner_user_id' => $sourceSemantic['owner_user_id'],
            'kind' => $sourceSemantic['kind'],
            'authority_status' => $sourceSemantic['authority_status'],
            'title' => $sourceSemantic['title'],
            'publisher' => $sourceSemantic['publisher'],
            'edition' => $sourceSemantic['edition'],
            'source_uri' => $sourceSemantic['source_uri'],
            'citation' => $sourceSemantic['citation'],
            'license' => $sourceSemantic['license'],
            'checksum_algorithm' => $sourceSemantic['checksum_algorithm'],
            'checksum' => $sourceSemantic['checksum'],
            'retrieved_at' => $sourceSemantic['retrieved_at'],
            'metadata' => $sourceSemantic['metadata'],
            'archived_at' => null,
            'created_by_user_id' => $input->actorId,
        ]);
        $this->storeRootEvent($source, $creationDecisions[$source->public_id], $context);

        $references = [];

        foreach ($candidatePlans as $candidatePlan) {
            $semantic = $candidatePlan['reference_plan']['semantic_entity'];
            $reference = FoodReference::query()->create([
                'public_id' => $semantic['public_id'],
                'stable_key' => $semantic['stable_key'],
                'visibility' => $semantic['visibility'],
                'owner_user_id' => $semantic['owner_user_id'],
                'is_generic' => $semantic['is_generic'],
                'archived_at' => null,
                'created_by_user_id' => $input->actorId,
            ]);
            $references[$reference->public_id] = $reference;
            $this->storeRootEvent($reference, $creationDecisions[$reference->public_id], $context);
        }

        $versions = [];

        foreach ($candidatePlans as $candidatePlan) {
            $semantic = $candidatePlan['version_plan']['semantic_entity'];
            $reference = $references[$semantic['reference_public_id']];
            $version = FoodReferenceVersion::query()->create([
                'public_id' => $semantic['public_id'],
                'food_reference_id' => $reference->id,
                'version_number' => $semantic['version_number'],
                'canonical_name' => $semantic['canonical_name'],
                'normalized_canonical_name' => $semantic['normalized_canonical_name'],
                'locale' => $semantic['locale'],
                'classification' => $semantic['classification'],
                'preparation_key' => $semantic['preparation_key'],
                'energy_basis_grams' => $semantic['energy_basis_grams'],
                'energy_kcal' => $semantic['energy_kcal'],
                'nutrient_values' => $semantic['nutrient_values'],
                'provenance' => $semantic['provenance'],
                'review_status' => $semantic['review_status'],
                'submitted_at' => null,
                'reviewed_at' => null,
                'published_at' => null,
                'activated_at' => null,
                'deactivated_at' => null,
                'withdrawn_at' => null,
                'archived_at' => null,
                'supersedes_food_reference_version_id' => null,
                'created_by_user_id' => $input->actorId,
            ]);
            $versions[$version->public_id] = $version;
            $this->storeRootEvent($version, $creationDecisions[$version->public_id], $context);
        }

        foreach ($candidatePlans as $candidatePlan) {
            $semantic = $candidatePlan['source_link_plan']['semantic_entity'];

            FoodReferenceVersionSource::query()->create([
                'food_reference_version_id' => $versions[$semantic['version_public_id']]->id,
                'food_source_id' => $source->id,
                'role' => $semantic['role'],
                'source_record_key' => $semantic['source_record_key'],
                'evidence_metadata' => $semantic['evidence_metadata'],
                'created_by_user_id' => $input->actorId,
            ]);
        }

        foreach ($candidatePlans as $candidatePlan) {
            $aliasPlans = array_values(array_filter(
                $candidatePlan['alias_plans'],
                fn (array $aliasPlan): bool => $aliasPlan['action'] !== 'excluded',
            ));
            usort($aliasPlans, fn (array $left, array $right): int => strcmp(
                $left['semantic_entity']['public_id'],
                $right['semantic_entity']['public_id'],
            ));

            foreach ($aliasPlans as $aliasPlan) {
                $semantic = $aliasPlan['semantic_entity'];
                $alias = FoodAlias::query()->create([
                    'public_id' => $semantic['public_id'],
                    'lineage_id' => $semantic['lineage_id'],
                    'food_reference_id' => $references[$semantic['reference_public_id']]->id,
                    'revision_number' => $semantic['revision_number'],
                    'supersedes_food_alias_id' => null,
                    'display_alias' => $semantic['display_alias'],
                    'normalized_alias' => $semantic['normalized_alias'],
                    'locale' => $semantic['locale'],
                    'alias_kind' => $semantic['alias_kind'],
                    'food_source_id' => $source->id,
                    'source_record_key' => $semantic['source_record_key'],
                    'provenance' => $semantic['provenance'],
                    'review_status' => $semantic['review_status'],
                    'submitted_at' => null,
                    'reviewed_at' => null,
                    'published_at' => null,
                    'activated_at' => null,
                    'deactivated_at' => null,
                    'withdrawn_at' => null,
                    'archived_at' => null,
                    'created_by_user_id' => $input->actorId,
                ]);
                $this->storeRootEvent($alias, $creationDecisions[$alias->public_id], $context);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $candidatePlans
     * @return array<string, array{command: CatalogLifecycleCommand, result: CatalogLifecycleResult}>
     */
    private function creationDecisions(
        LoadedApprovedCatalogImportArtifacts $artifacts,
        ApprovedCatalogImportExecutionInput $input,
        array $candidatePlans,
    ): array {
        $decisions = [];
        $sourcePublicId = $artifacts->applyPlan['source_plan']['semantic_entity']['public_id'];
        $this->addDecision(
            $decisions,
            $artifacts,
            $input,
            $this->sourcePolicy,
            new FoodSourceLifecycleSnapshot($sourcePublicId, false, null),
            CatalogLifecycleSubjectType::Source,
            CatalogLifecycleOperation::CreateSource,
        );

        foreach ($candidatePlans as $candidatePlan) {
            $referencePublicId = $candidatePlan['reference_plan']['semantic_entity']['public_id'];
            $versionPublicId = $candidatePlan['version_plan']['semantic_entity']['public_id'];
            $this->addDecision(
                $decisions,
                $artifacts,
                $input,
                $this->referencePolicy,
                new FoodReferenceLifecycleSnapshot($referencePublicId, false, null),
                CatalogLifecycleSubjectType::Reference,
                CatalogLifecycleOperation::CreateReference,
            );
            $this->addDecision(
                $decisions,
                $artifacts,
                $input,
                $this->versionPolicy,
                new FoodReferenceVersionLifecycleSnapshot($versionPublicId, false, null),
                CatalogLifecycleSubjectType::ReferenceVersion,
                CatalogLifecycleOperation::CreateDraft,
            );

            foreach ($candidatePlan['alias_plans'] as $aliasPlan) {
                if ($aliasPlan['action'] === 'excluded') {
                    continue;
                }

                $aliasPublicId = $aliasPlan['semantic_entity']['public_id'];
                $this->addDecision(
                    $decisions,
                    $artifacts,
                    $input,
                    $this->aliasPolicy,
                    new FoodAliasLifecycleSnapshot($aliasPublicId, false, null),
                    CatalogLifecycleSubjectType::Alias,
                    CatalogLifecycleOperation::CreateDraft,
                );
            }
        }

        if (count($decisions) !== 10) {
            throw new RuntimeException('The approved graph did not produce exactly ten creation-policy decisions.');
        }

        return $decisions;
    }

    /**
     * @param  array<string, array{command: CatalogLifecycleCommand, result: CatalogLifecycleResult}>  $decisions
     */
    private function addDecision(
        array &$decisions,
        LoadedApprovedCatalogImportArtifacts $artifacts,
        ApprovedCatalogImportExecutionInput $input,
        CatalogLifecyclePolicy $policy,
        CatalogLifecycleSnapshot $snapshot,
        CatalogLifecycleSubjectType $subjectType,
        CatalogLifecycleOperation $operation,
    ): void {
        $subjectPublicId = $snapshot->subjectId();
        $idempotencyKey = CatalogImportDeterministicIdentity::lifecycleIdempotencyKey(
            new CatalogImportLifecycleIdempotencyInput(
                manifestChecksum: $artifacts->manifest->checksum,
                subjectType: $subjectType,
                subjectPublicId: $subjectPublicId,
                operation: $operation,
                actorId: (string) $input->actorId,
                actorReference: $input->actorReference,
                reason: $input->reason,
                occurredAt: $input->occurredAt,
            ),
        );
        $command = new CatalogLifecycleCommand(
            subjectType: $subjectType,
            subjectId: $subjectPublicId,
            operation: $operation,
            actorId: (string) $input->actorId,
            reason: $input->reason,
            idempotencyKey: $idempotencyKey,
            occurredAt: $input->occurredAt,
        );
        $result = $policy->evaluate($command, $snapshot);

        if ($result->outcome !== CatalogLifecycleOutcome::Succeeded) {
            throw new RuntimeException("The committed creation policy rejected {$subjectPublicId}.");
        }

        $decisions[$subjectPublicId] = compact('command', 'result');
    }

    /** @param array{command: CatalogLifecycleCommand, result: CatalogLifecycleResult} $decision */
    private function storeRootEvent(
        Model $subject,
        array $decision,
        CatalogLifecycleExecutionContext $context,
    ): void {
        $stored = $this->eventStore->storeRoot($this->rootEventFactory->create(
            command: $decision['command'],
            context: $context,
            subjectInternalId: (int) $subject->getKey(),
            subjectPublicId: $subject->getAttribute('public_id'),
            result: $decision['result'],
        ));

        if ($stored->toLifecycleResult() != $decision['result']) {
            throw new RuntimeException('The stored root lifecycle event did not match its creation decision.');
        }
    }
}
