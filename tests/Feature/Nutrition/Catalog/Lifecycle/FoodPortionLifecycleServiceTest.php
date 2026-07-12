<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\FoodPortionLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodPortionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

function portionServiceM2344(): FoodPortionLifecycleService
{
    $store = new EloquentCatalogLifecycleEventStore;

    return new FoodPortionLifecycleService(new FoodPortionLifecyclePolicy, $store, new CatalogLifecycleReplayGuard($store), new CatalogLifecycleRootEventFactory, new CatalogLifecycleProjectionStateResolver);
}

function portionCommandM2344(FoodPortion $portion, User $actor, CatalogLifecycleOperation $operation, ?string $reason = null): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand(CatalogLifecycleSubjectType::Portion, $portion->public_id, $operation, (string) $actor->id, $reason, (string) Str::uuid7(), new DateTimeImmutable('2026-07-12T16:15:00-03:00'));
}

function portionContextM2344(User $actor): CatalogLifecycleExecutionContext
{
    return new CatalogLifecycleExecutionContext($actor->id, "audit:user:{$actor->id}");
}

function completePortionM2344(User $actor, array $overrides = []): FoodPortion
{
    $reference = FoodReference::factory()->create();
    $source = FoodSource::factory()->eligible()->create();

    return FoodPortion::factory()->create(array_replace([
        'food_reference_id' => $reference->id, 'food_source_id' => $source->id,
        'source_record_key' => 'portion:source', 'provenance' => ['origin' => 'measurement'],
        'created_by_user_id' => $actor->id,
    ], $overrides));
}

it('submits complete portion evidence without quantity conversion or defaults', function () {
    $actor = User::factory()->create();
    $portion = completePortionM2344($actor);
    $quantity = $portion->unit_quantity;
    $grams = $portion->gram_weight;

    $execution = portionServiceM2344()->submitForReview(portionCommandM2344($portion, $actor, CatalogLifecycleOperation::SubmitForReview), portionContextM2344($actor));

    expect($execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($portion->refresh()->unit_quantity)->toBe($quantity)
        ->and($portion->gram_weight)->toBe($grams)
        ->and($execution->rootEvent->metadata)->toBe([]);
});

it('preserves ordered evidence validation reasons', function (array $overrides, CatalogLifecycleReason $reason) {
    $actor = User::factory()->create();
    $portion = completePortionM2344($actor, $overrides);

    $execution = portionServiceM2344()->submitForReview(portionCommandM2344($portion, $actor, CatalogLifecycleOperation::SubmitForReview), portionContextM2344($actor));

    expect($execution->lifecycleResult->reason)->toBe($reason)
        ->and($portion->refresh()->submitted_at)->toBeNull();
})->with([
    'incomplete' => [['display_label' => ''], CatalogLifecycleReason::IncompleteContent],
    'provenance' => [['provenance' => null], CatalogLifecycleReason::ProvenanceIncomplete],
    'preparation' => [['preparation_key' => 'fried'], CatalogLifecycleReason::PortionEvidenceInvalid],
]);

it('rejects nonpositive quantity evidence at the PostgreSQL integrity boundary', function (array $overrides) {
    $actor = User::factory()->create();

    expect(fn () => completePortionM2344($actor, $overrides))->toThrow(QueryException::class);
})->with([
    'quantity' => [['unit_quantity' => '0.0000']],
    'grams' => [['gram_weight' => '0.0000']],
]);

it('applies source review activation and ownership rules', function (string $authority, CatalogLifecycleReason|CatalogLifecycleOutcome $expected) {
    $actor = User::factory()->create();
    $portion = completePortionM2344($actor);
    $portion->source()->update(['authority_status' => $authority]);
    $execution = portionServiceM2344()->submitForReview(portionCommandM2344($portion, $actor, CatalogLifecycleOperation::SubmitForReview), portionContextM2344($actor));

    $actual = $execution->lifecycleResult->outcome === CatalogLifecycleOutcome::Succeeded
        ? $execution->lifecycleResult->outcome
        : $execution->lifecycleResult->reason;

    expect($actual)->toBe($expected);
})->with([
    ['eligible', CatalogLifecycleOutcome::Succeeded],
    ['untrusted', CatalogLifecycleOutcome::Succeeded],
    ['prohibited', CatalogLifecycleReason::SourceProhibited],
]);

it('blocks private source ownership mismatch', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $reference = FoodReference::factory()->privateFor($actor)->create();
    $source = FoodSource::factory()->privateFor($other)->eligible()->create();
    $portion = completePortionM2344($actor, ['food_reference_id' => $reference->id, 'food_source_id' => $source->id]);

    $execution = portionServiceM2344()->submitForReview(portionCommandM2344($portion, $actor, CatalogLifecycleOperation::SubmitForReview), portionContextM2344($actor));

    expect($execution->lifecycleResult->reason)->toBe(CatalogLifecycleReason::SourceScopeMismatch);
});

it('blocks active portion conflict without replacing the active row', function () {
    $actor = User::factory()->create();
    $portion = completePortionM2344($actor, ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now()]);
    $active = FoodPortion::factory()->active()->create([
        'food_reference_id' => $portion->food_reference_id, 'locale' => $portion->locale,
        'portion_key' => $portion->portion_key, 'preparation_key' => $portion->preparation_key,
    ]);

    $execution = portionServiceM2344()->activate(portionCommandM2344($portion, $actor, CatalogLifecycleOperation::Activate, 'Activation.'), portionContextM2344($actor));

    expect($execution->lifecycleResult->reason)->toBe(CatalogLifecycleReason::ActivePortionConflict)
        ->and($active->refresh()->deactivated_at)->toBeNull()
        ->and($portion->refresh()->activated_at)->toBeNull();
});

it('supports approve publish activate deactivate reactivate withdraw and archive projections', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $portion = completePortionM2344($author, ['review_status' => 'pending_review', 'submitted_at' => now(), 'submitted_by_user_id' => $author->id]);
    $service = portionServiceM2344();

    $service->approve(portionCommandM2344($portion, $reviewer, CatalogLifecycleOperation::Approve, 'Approve.'), portionContextM2344($reviewer));
    $service->publish(portionCommandM2344($portion, $reviewer, CatalogLifecycleOperation::Publish, 'Publish.'), portionContextM2344($reviewer));
    $service->activate(portionCommandM2344($portion, $reviewer, CatalogLifecycleOperation::Activate, 'Activate.'), portionContextM2344($reviewer));
    $service->deactivate(portionCommandM2344($portion, $reviewer, CatalogLifecycleOperation::Deactivate, 'Deactivate.'), portionContextM2344($reviewer));
    $service->reactivate(portionCommandM2344($portion, $reviewer, CatalogLifecycleOperation::Reactivate, 'Reactivate.'), portionContextM2344($reviewer));

    expect($portion->refresh()->activated_by_user_id)->toBe($reviewer->id)
        ->and($portion->deactivated_at)->toBeNull();
});

it('keeps portion creator submitter and reviewer identities separate', function () {
    $creator = User::factory()->create();
    $submitter = User::factory()->create();
    $reviewer = User::factory()->create();
    $portion = completePortionM2344($creator);
    $service = portionServiceM2344();
    $submissionCommand = portionCommandM2344($portion, $submitter, CatalogLifecycleOperation::SubmitForReview);

    $service->submitForReview($submissionCommand, portionContextM2344($submitter));
    $portion->refresh();

    expect($portion->created_by_user_id)->toBe($creator->id)
        ->and($portion->submitted_by_user_id)->toBe($submitter->id)
        ->and($portion->submitted_at->toDateTimeImmutable())->toEqual($submissionCommand->occurredAt);

    $creatorApproval = $service->approve(
        portionCommandM2344($portion, $creator, CatalogLifecycleOperation::Approve, 'Creator approval.'),
        portionContextM2344($creator),
    );

    expect($creatorApproval->lifecycleResult->reason)->toBe(CatalogLifecycleReason::SelfApprovalProhibited)
        ->and($portion->refresh()->review_status->value)->toBe('pending_review');

    $submitterApproval = $service->approve(
        portionCommandM2344($portion, $submitter, CatalogLifecycleOperation::Approve, 'Submitter approval.'),
        portionContextM2344($submitter),
    );

    expect($submitterApproval->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($portion->refresh()->reviewed_by_user_id)->toBe($submitter->id);

    $reviewerPortion = completePortionM2344($creator, [
        'review_status' => 'pending_review',
        'submitted_at' => now(),
        'submitted_by_user_id' => $submitter->id,
    ]);
    $reviewerApproval = $service->approve(
        portionCommandM2344($reviewerPortion, $reviewer, CatalogLifecycleOperation::Approve, 'Reviewer approval.'),
        portionContextM2344($reviewer),
    );

    expect($reviewerApproval->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($reviewerPortion->refresh()->reviewed_by_user_id)->toBe($reviewer->id);
});

it('applies return reject withdraw archive and deterministic no-op operations', function (string $method, CatalogLifecycleOperation $operation, array $state, CatalogLifecycleOutcome $outcome) {
    $actor = User::factory()->create();
    $portion = completePortionM2344($actor, $state);

    $execution = portionServiceM2344()->{$method}(
        portionCommandM2344($portion, $actor, $operation, 'Governed reason.'), portionContextM2344($actor),
    );

    expect($execution->lifecycleResult->outcome)->toBe($outcome);
})->with([
    'return' => ['returnToDraft', CatalogLifecycleOperation::ReturnToDraft, ['review_status' => 'pending_review', 'submitted_at' => now()], CatalogLifecycleOutcome::Succeeded],
    'reject' => ['reject', CatalogLifecycleOperation::Reject, ['review_status' => 'pending_review', 'submitted_at' => now()], CatalogLifecycleOutcome::Succeeded],
    'withdraw' => ['withdraw', CatalogLifecycleOperation::Withdraw, ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now()], CatalogLifecycleOutcome::Succeeded],
    'archive' => ['archive', CatalogLifecycleOperation::Archive, [], CatalogLifecycleOutcome::Succeeded],
    'archive no op' => ['archive', CatalogLifecycleOperation::Archive, ['archived_at' => now()], CatalogLifecycleOutcome::NoOp],
]);
