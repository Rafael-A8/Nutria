<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\FoodReferenceVersionLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Support\Str;

function versionServiceM2344(): FoodReferenceVersionLifecycleService
{
    $store = new EloquentCatalogLifecycleEventStore;

    return new FoodReferenceVersionLifecycleService(
        new FoodReferenceVersionLifecyclePolicy,
        $store,
        new CatalogLifecycleReplayGuard($store),
        new CatalogLifecycleRootEventFactory,
        new CatalogLifecycleProjectionStateResolver,
    );
}

function versionCommandM2344(FoodReferenceVersion $version, User $actor, CatalogLifecycleOperation $operation, ?string $reason = null): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::ReferenceVersion,
        $version->public_id,
        $operation,
        (string) $actor->id,
        $reason,
        (string) Str::uuid7(),
        new DateTimeImmutable('2026-07-12T15:45:00.123456-03:00'),
    );
}

function versionContextM2344(User $actor): CatalogLifecycleExecutionContext
{
    return new CatalogLifecycleExecutionContext($actor->id, "audit:user:{$actor->id}");
}

function completeVersionM2344(User $actor, array $overrides = [], ?Closure $configureDraft = null): FoodReferenceVersion
{
    $lifecycleColumns = [
        'review_status',
        'submitted_at',
        'submitted_by_user_id',
        'reviewed_at',
        'reviewed_by_user_id',
        'review_reason',
        'published_at',
        'published_by_user_id',
        'activated_at',
        'activated_by_user_id',
        'deactivated_at',
        'deactivated_by_user_id',
        'deactivation_reason',
        'withdrawn_at',
        'withdrawn_by_user_id',
        'withdrawal_reason',
        'archived_at',
        'archived_by_user_id',
        'archive_reason',
    ];
    $lifecycleOverrides = array_intersect_key($overrides, array_flip($lifecycleColumns));
    $draftOverrides = array_diff_key($overrides, $lifecycleOverrides);
    $reference = FoodReference::factory()->create();
    $version = FoodReferenceVersion::factory()->create(array_replace([
        'food_reference_id' => $reference->id,
        'provenance' => ['origin' => 'curated'],
        'energy_basis_grams' => '100.0000',
        'energy_kcal' => '120.0000',
        'created_by_user_id' => $actor->id,
        'review_status' => 'draft',
    ], $draftOverrides));
    $source = FoodSource::factory()->eligible()->create();
    FoodReferenceVersionSource::factory()->primary()->create([
        'food_reference_version_id' => $version->id,
        'food_source_id' => $source->id,
        'source_record_key' => 'catalog:primary',
    ]);

    if ($configureDraft !== null) {
        $configureDraft($version);
    }

    if ($lifecycleOverrides !== []) {
        $version->forceFill($lifecycleOverrides)->save();
    }

    return $version;
}

it('submits a complete draft atomically without changing content', function () {
    $actor = User::factory()->create();
    $version = completeVersionM2344($actor);
    $content = $version->only(['canonical_name', 'normalized_canonical_name', 'classification', 'energy_kcal']);
    $command = versionCommandM2344($version, $actor, CatalogLifecycleOperation::SubmitForReview);

    $execution = versionServiceM2344()->submitForReview($command, versionContextM2344($actor));
    $version->refresh();

    expect($execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($execution->replayed)->toBeFalse()
        ->and($version->review_status)->toBe(CatalogReviewStatus::PendingReview)
        ->and($version->submitted_by_user_id)->toBe($actor->id)
        ->and($version->only(array_keys($content)))->toBe($content)
        ->and(CatalogLifecycleEvent::query()->where('idempotency_key', $command->idempotencyKey)->count())->toBe(1)
        ->and($execution->rootEvent->occurredAt)->toEqual($command->occurredAt);
});

it('audits ordered submission failures without changing projection', function (array $overrides, CatalogLifecycleReason $reason) {
    $actor = User::factory()->create();
    $version = completeVersionM2344($actor, $overrides);
    $command = versionCommandM2344($version, $actor, CatalogLifecycleOperation::SubmitForReview);

    $execution = versionServiceM2344()->submitForReview($command, versionContextM2344($actor));

    expect($execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::ValidationFailed)
        ->and($execution->lifecycleResult->reason)->toBe($reason)
        ->and($version->refresh()->review_status)->toBe(CatalogReviewStatus::Draft)
        ->and($execution->rootEvent->eligibilityReasons[0])->toBe($reason);
})->with([
    'incomplete' => [['canonical_name' => ''], CatalogLifecycleReason::IncompleteContent],
    'provenance' => [['provenance' => null], CatalogLifecycleReason::ProvenanceIncomplete],
]);

it('allows untrusted review but blocks prohibited primary evidence', function () {
    $actor = User::factory()->create();
    $allowed = completeVersionM2344($actor);
    $allowed->sourceLinks()->first()->source()->update(['authority_status' => 'untrusted']);
    $blocked = completeVersionM2344($actor);
    $blocked->sourceLinks()->first()->source()->update(['authority_status' => 'prohibited']);

    $allowedResult = versionServiceM2344()->submitForReview(
        versionCommandM2344($allowed, $actor, CatalogLifecycleOperation::SubmitForReview), versionContextM2344($actor),
    );
    $blockedResult = versionServiceM2344()->submitForReview(
        versionCommandM2344($blocked, $actor, CatalogLifecycleOperation::SubmitForReview), versionContextM2344($actor),
    );

    expect($allowedResult->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($blockedResult->lifecycleResult->reason)->toBe(CatalogLifecycleReason::SourceProhibited);
});

it('returns to draft approves rejects and publishes with exact projection fields', function (string $method, CatalogLifecycleOperation $operation, array $state, string $expectedStatus) {
    $actor = User::factory()->create();
    $reviewer = User::factory()->create();
    $version = completeVersionM2344($actor, $state);
    $command = versionCommandM2344($version, $reviewer, $operation, 'Governed reason.');

    versionServiceM2344()->{$method}($command, versionContextM2344($reviewer));
    $version->refresh();

    expect($version->review_status->value)->toBe($expectedStatus);
})->with([
    'return' => ['returnToDraft', CatalogLifecycleOperation::ReturnToDraft, ['review_status' => 'pending_review', 'submitted_at' => now(), 'submitted_by_user_id' => 1], 'draft'],
    'approve' => ['approve', CatalogLifecycleOperation::Approve, ['review_status' => 'pending_review', 'submitted_at' => now()], 'approved'],
    'reject' => ['reject', CatalogLifecycleOperation::Reject, ['review_status' => 'pending_review', 'submitted_at' => now()], 'rejected'],
    'publish' => ['publish', CatalogLifecycleOperation::Publish, ['review_status' => 'approved', 'reviewed_at' => now()], 'approved'],
]);

it('keeps version creator submitter and reviewer identities separate', function () {
    $creator = User::factory()->create();
    $submitter = User::factory()->create();
    $reviewer = User::factory()->create();
    $version = completeVersionM2344($creator);
    $service = versionServiceM2344();
    $submissionCommand = versionCommandM2344($version, $submitter, CatalogLifecycleOperation::SubmitForReview);

    $service->submitForReview($submissionCommand, versionContextM2344($submitter));
    $version->refresh();

    expect($version->created_by_user_id)->toBe($creator->id)
        ->and($version->submitted_by_user_id)->toBe($submitter->id)
        ->and($version->submitted_at->toDateTimeImmutable())->toEqual($submissionCommand->occurredAt);

    $creatorApproval = $service->approve(
        versionCommandM2344($version, $creator, CatalogLifecycleOperation::Approve, 'Creator approval.'),
        versionContextM2344($creator),
    );

    expect($creatorApproval->lifecycleResult->reason)->toBe(CatalogLifecycleReason::SelfApprovalProhibited)
        ->and($version->refresh()->review_status)->toBe(CatalogReviewStatus::PendingReview);

    $submitterApproval = $service->approve(
        versionCommandM2344($version, $submitter, CatalogLifecycleOperation::Approve, 'Submitter approval.'),
        versionContextM2344($submitter),
    );

    expect($submitterApproval->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($version->refresh()->reviewed_by_user_id)->toBe($submitter->id);

    $reviewerVersion = completeVersionM2344($creator, [
        'review_status' => 'pending_review',
        'submitted_at' => now(),
        'submitted_by_user_id' => $submitter->id,
    ]);
    $reviewerApproval = $service->approve(
        versionCommandM2344($reviewerVersion, $reviewer, CatalogLifecycleOperation::Approve, 'Reviewer approval.'),
        versionContextM2344($reviewer),
    );

    expect($reviewerApproval->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($reviewerVersion->refresh()->reviewed_by_user_id)->toBe($reviewer->id);
});

it('activates eligible published knowledge and does not replace an active conflict', function () {
    $actor = User::factory()->create();
    $version = completeVersionM2344($actor, ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now()]);
    $command = versionCommandM2344($version, $actor, CatalogLifecycleOperation::Activate, 'Activation.');

    $execution = versionServiceM2344()->activate($command, versionContextM2344($actor));

    expect($execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($version->refresh()->activated_by_user_id)->toBe($actor->id);
});

it('audits activation blockers without projection mutation', function (string $blocker, CatalogLifecycleReason $reason) {
    $actor = User::factory()->create();
    $version = completeVersionM2344(
        $actor,
        ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now()],
        function (FoodReferenceVersion $draftVersion) use ($blocker): void {
            if ($blocker === 'nutrition') {
                $draftVersion->update(['energy_kcal' => null]);
            }

            if ($blocker === 'missing_source') {
                $draftVersion->sourceLinks()->delete();
            }

            if (in_array($blocker, ['app_generated', 'scope'], true)) {
                $draftVersion->sourceLinks()->delete();
                $replacementSource = $blocker === 'app_generated'
                    ? FoodSource::factory()->eligible()->create(['kind' => 'app_generated_estimate'])
                    : FoodSource::factory()->privateFor(User::factory()->create())->eligible()->create();

                FoodReferenceVersionSource::factory()->primary()->create([
                    'food_reference_version_id' => $draftVersion->id,
                    'food_source_id' => $replacementSource->id,
                    'source_record_key' => 'catalog:replacement',
                ]);
            }
        },
    );

    match ($blocker) {
        'source' => $version->sourceLinks()->first()->source()->update(['authority_status' => 'untrusted']),
        'archived_source' => $version->sourceLinks()->first()->source()->update(['archived_at' => now()]),
        'parent' => $version->reference()->update(['archived_at' => now()]),
        'active_conflict' => FoodReferenceVersion::factory()->active()->create([
            'food_reference_id' => $version->food_reference_id, 'version_number' => 2,
        ]),
        'successor' => FoodReferenceVersion::factory()->create([
            'food_reference_id' => $version->food_reference_id, 'version_number' => 2,
            'supersedes_food_reference_version_id' => $version->id,
        ]),
        default => null,
    };

    $execution = versionServiceM2344()->activate(
        versionCommandM2344($version, $actor, CatalogLifecycleOperation::Activate, 'Activation.'), versionContextM2344($actor),
    );

    expect($execution->lifecycleResult->reason)->toBe($reason)
        ->and($version->refresh()->activated_at)->toBeNull();
})->with([
    ['nutrition', CatalogLifecycleReason::NutritionIncomplete],
    ['source', CatalogLifecycleReason::SourceIneligible],
    ['missing_source', CatalogLifecycleReason::PrimarySourceMissing],
    ['app_generated', CatalogLifecycleReason::SourceIneligible],
    ['archived_source', CatalogLifecycleReason::SourceArchived],
    ['parent', CatalogLifecycleReason::ParentArchived],
    ['scope', CatalogLifecycleReason::SourceScopeMismatch],
    ['active_conflict', CatalogLifecycleReason::ActiveVersionConflict],
    ['successor', CatalogLifecycleReason::SupersededPredecessor],
]);

it('deactivates reactivates withdraws archives and persists deterministic no ops', function () {
    $actor = User::factory()->create();
    $version = completeVersionM2344($actor, [
        'review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now(), 'activated_at' => now(),
    ]);
    $service = versionServiceM2344();

    $service->deactivate(versionCommandM2344($version, $actor, CatalogLifecycleOperation::Deactivate, 'Pause.'), versionContextM2344($actor));
    $version->refresh();
    $noOp = $service->deactivate(versionCommandM2344($version, $actor, CatalogLifecycleOperation::Deactivate, 'Again.'), versionContextM2344($actor));
    $service->reactivate(versionCommandM2344($version, $actor, CatalogLifecycleOperation::Reactivate, 'Resume.'), versionContextM2344($actor));

    expect($noOp->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::NoOp)
        ->and(CatalogLifecycleEvent::query()->where('subject_id', $version->id)->count())->toBe(3);
});

it('withdraws and archives eligible version states without clearing lifecycle history', function (string $method, CatalogLifecycleOperation $operation, array $state) {
    $actor = User::factory()->create();
    $version = completeVersionM2344($actor, $state);
    $publishedAt = $version->published_at;

    $execution = versionServiceM2344()->{$method}(
        versionCommandM2344($version, $actor, $operation, 'Governed reason.'), versionContextM2344($actor),
    );

    expect($execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($version->refresh()->published_at)->toEqual($publishedAt);
})->with([
    'withdraw' => ['withdraw', CatalogLifecycleOperation::Withdraw, ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now()]],
    'archive' => ['archive', CatalogLifecycleOperation::Archive, ['review_status' => 'approved', 'reviewed_at' => now()]],
]);
