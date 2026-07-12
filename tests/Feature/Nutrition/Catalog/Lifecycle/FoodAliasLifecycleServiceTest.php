<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\FoodAliasLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodAliasLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Support\Str;

function aliasServiceM2344(): FoodAliasLifecycleService
{
    $store = new EloquentCatalogLifecycleEventStore;

    return new FoodAliasLifecycleService(new FoodAliasLifecyclePolicy, $store, new CatalogLifecycleReplayGuard($store), new CatalogLifecycleRootEventFactory, new CatalogLifecycleProjectionStateResolver);
}

function aliasCommandM2344(FoodAlias $alias, User $actor, CatalogLifecycleOperation $operation, ?string $reason = null): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand(CatalogLifecycleSubjectType::Alias, $alias->public_id, $operation, (string) $actor->id, $reason, (string) Str::uuid7(), new DateTimeImmutable('2026-07-12T16:00:00-03:00'));
}

function aliasContextM2344(User $actor): CatalogLifecycleExecutionContext
{
    return new CatalogLifecycleExecutionContext($actor->id, "audit:user:{$actor->id}");
}

function completeAliasM2344(User $actor, array $overrides = []): FoodAlias
{
    $reference = FoodReference::factory()->create();
    $source = FoodSource::factory()->eligible()->create();

    return FoodAlias::factory()->create(array_replace([
        'food_reference_id' => $reference->id, 'food_source_id' => $source->id,
        'source_record_key' => 'alias:source', 'provenance' => ['origin' => 'curated'],
        'created_by_user_id' => $actor->id,
    ], $overrides));
}

it('submits complete aliases and audits incomplete evidence', function () {
    $actor = User::factory()->create();
    $complete = completeAliasM2344($actor);
    $incomplete = completeAliasM2344($actor, ['normalized_alias' => '', 'locale' => '', 'provenance' => null]);

    $success = aliasServiceM2344()->submitForReview(aliasCommandM2344($complete, $actor, CatalogLifecycleOperation::SubmitForReview), aliasContextM2344($actor));
    $failure = aliasServiceM2344()->submitForReview(aliasCommandM2344($incomplete, $actor, CatalogLifecycleOperation::SubmitForReview), aliasContextM2344($actor));

    expect($success->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($failure->lifecycleResult->eligibility->reasons())->toBe([
            CatalogLifecycleReason::IncompleteContent,
            CatalogLifecycleReason::AliasNormalizationMissing,
            CatalogLifecycleReason::AliasLocaleMissing,
            CatalogLifecycleReason::ProvenanceIncomplete,
        ])
        ->and($incomplete->refresh()->submitted_at)->toBeNull();
});

it('allows untrusted review and blocks prohibited evidence', function () {
    $actor = User::factory()->create();
    $untrusted = completeAliasM2344($actor);
    $untrusted->source()->update(['authority_status' => 'untrusted']);
    $prohibited = completeAliasM2344($actor);
    $prohibited->source()->update(['authority_status' => 'prohibited']);

    expect(aliasServiceM2344()->submitForReview(aliasCommandM2344($untrusted, $actor, CatalogLifecycleOperation::SubmitForReview), aliasContextM2344($actor))->lifecycleResult->outcome)
        ->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and(aliasServiceM2344()->submitForReview(aliasCommandM2344($prohibited, $actor, CatalogLifecycleOperation::SubmitForReview), aliasContextM2344($actor))->lifecycleResult->reason)
        ->toBe(CatalogLifecycleReason::SourceProhibited);
});

it('enforces generic brand and common compatibility', function (bool $generic, string $kind, CatalogLifecycleOutcome $outcome) {
    $actor = User::factory()->create();
    $reference = FoodReference::factory()->create(['is_generic' => $generic]);
    $alias = completeAliasM2344($actor, ['food_reference_id' => $reference->id, 'alias_kind' => $kind]);

    $execution = aliasServiceM2344()->submitForReview(aliasCommandM2344($alias, $actor, CatalogLifecycleOperation::SubmitForReview), aliasContextM2344($actor));

    expect($execution->lifecycleResult->outcome)->toBe($outcome);
})->with([
    'generic valid' => [true, 'generic', CatalogLifecycleOutcome::Succeeded],
    'generic mismatch' => [false, 'generic', CatalogLifecycleOutcome::ValidationFailed],
    'brand mismatch' => [true, 'brand', CatalogLifecycleOutcome::ValidationFailed],
    'common valid' => [false, 'common', CatalogLifecycleOutcome::Succeeded],
    'regional valid' => [true, 'regional', CatalogLifecycleOutcome::Succeeded],
]);

it('enforces private and global source scope without exposing another owner', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $reference = FoodReference::factory()->privateFor($actor)->create();
    $source = FoodSource::factory()->privateFor($other)->eligible()->create();
    $alias = completeAliasM2344($actor, ['food_reference_id' => $reference->id, 'food_source_id' => $source->id]);

    $execution = aliasServiceM2344()->submitForReview(aliasCommandM2344($alias, $actor, CatalogLifecycleOperation::SubmitForReview), aliasContextM2344($actor));

    expect($execution->lifecycleResult->reason)->toBe(CatalogLifecycleReason::SourceScopeMismatch)
        ->and(json_encode($execution->rootEvent->metadata))->not->toContain((string) $other->id);
});

it('detects only same-reference active key conflicts and never deactivates them', function () {
    $actor = User::factory()->create();
    $alias = completeAliasM2344($actor, ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now()]);
    $conflict = FoodAlias::factory()->active()->create([
        'food_reference_id' => $alias->food_reference_id, 'locale' => $alias->locale,
        'normalized_alias' => $alias->normalized_alias,
    ]);

    $execution = aliasServiceM2344()->activate(aliasCommandM2344($alias, $actor, CatalogLifecycleOperation::Activate, 'Activation.'), aliasContextM2344($actor));

    expect($execution->lifecycleResult->reason)->toBe(CatalogLifecycleReason::ActiveAliasConflict)
        ->and($alias->refresh()->activated_at)->toBeNull()
        ->and($conflict->refresh()->deactivated_at)->toBeNull();
});

it('does not treat a cross-reference alias collision as a local active conflict', function () {
    $actor = User::factory()->create();
    $alias = completeAliasM2344($actor, ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now()]);
    FoodAlias::factory()->active()->create([
        'locale' => $alias->locale, 'normalized_alias' => $alias->normalized_alias,
    ]);

    $execution = aliasServiceM2344()->activate(
        aliasCommandM2344($alias, $actor, CatalogLifecycleOperation::Activate, 'Activation.'), aliasContextM2344($actor),
    );

    expect($execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($alias->refresh()->activated_at)->not->toBeNull();
});

it('applies remaining alias lifecycle operations', function (string $method, CatalogLifecycleOperation $operation, array $state) {
    $actor = User::factory()->create();
    $alias = completeAliasM2344($actor, $state);

    $execution = aliasServiceM2344()->{$method}(
        aliasCommandM2344($alias, $actor, $operation, 'Governed reason.'), aliasContextM2344($actor),
    );

    expect($execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded);
})->with([
    'return' => ['returnToDraft', CatalogLifecycleOperation::ReturnToDraft, ['review_status' => 'pending_review', 'submitted_at' => now()]],
    'deactivate' => ['deactivate', CatalogLifecycleOperation::Deactivate, ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now(), 'activated_at' => now()]],
    'reactivate' => ['reactivate', CatalogLifecycleOperation::Reactivate, ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now(), 'activated_at' => now(), 'deactivated_at' => now()]],
    'withdraw' => ['withdraw', CatalogLifecycleOperation::Withdraw, ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now()]],
    'archive' => ['archive', CatalogLifecycleOperation::Archive, []],
]);

it('supports approval publication activation deactivation reactivation withdrawal archive and no-op audit', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $alias = completeAliasM2344($author, ['review_status' => 'pending_review', 'submitted_at' => now(), 'submitted_by_user_id' => $author->id]);
    $service = aliasServiceM2344();

    $service->approve(aliasCommandM2344($alias, $reviewer, CatalogLifecycleOperation::Approve, 'Approved.'), aliasContextM2344($reviewer));
    $service->publish(aliasCommandM2344($alias, $reviewer, CatalogLifecycleOperation::Publish, 'Published.'), aliasContextM2344($reviewer));
    $service->activate(aliasCommandM2344($alias, $reviewer, CatalogLifecycleOperation::Activate, 'Active.'), aliasContextM2344($reviewer));
    $noOp = $service->activate(aliasCommandM2344($alias, $reviewer, CatalogLifecycleOperation::Activate, 'Again.'), aliasContextM2344($reviewer));

    expect($noOp->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::NoOp)
        ->and(CatalogLifecycleEvent::query()->where('subject_id', $alias->id)->count())->toBe(4);
});

it('keeps alias creator submitter and reviewer identities separate', function () {
    $creator = User::factory()->create();
    $submitter = User::factory()->create();
    $reviewer = User::factory()->create();
    $alias = completeAliasM2344($creator);
    $service = aliasServiceM2344();
    $submissionCommand = aliasCommandM2344($alias, $submitter, CatalogLifecycleOperation::SubmitForReview);

    $service->submitForReview($submissionCommand, aliasContextM2344($submitter));
    $alias->refresh();

    expect($alias->created_by_user_id)->toBe($creator->id)
        ->and($alias->submitted_by_user_id)->toBe($submitter->id)
        ->and($alias->submitted_at->toDateTimeImmutable())->toEqual($submissionCommand->occurredAt);

    $creatorApproval = $service->approve(
        aliasCommandM2344($alias, $creator, CatalogLifecycleOperation::Approve, 'Creator approval.'),
        aliasContextM2344($creator),
    );

    expect($creatorApproval->lifecycleResult->reason)->toBe(CatalogLifecycleReason::SelfApprovalProhibited)
        ->and($alias->refresh()->review_status->value)->toBe('pending_review');

    $submitterApproval = $service->approve(
        aliasCommandM2344($alias, $submitter, CatalogLifecycleOperation::Approve, 'Submitter approval.'),
        aliasContextM2344($submitter),
    );

    expect($submitterApproval->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($alias->refresh()->reviewed_by_user_id)->toBe($submitter->id);

    $reviewerAlias = completeAliasM2344($creator, [
        'review_status' => 'pending_review',
        'submitted_at' => now(),
        'submitted_by_user_id' => $submitter->id,
    ]);
    $reviewerApproval = $service->approve(
        aliasCommandM2344($reviewerAlias, $reviewer, CatalogLifecycleOperation::Approve, 'Reviewer approval.'),
        aliasContextM2344($reviewer),
    );

    expect($reviewerApproval->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($reviewerAlias->refresh()->reviewed_by_user_id)->toBe($reviewer->id);
});
