<?php

namespace App\Nutrition\Application\Catalog\Persistence;

use App\Models\User;
use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportGraphState;
use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportOutcome;
use App\Nutrition\Application\Catalog\Import\Exceptions\ApprovedCatalogImportPostWriteVerificationException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportApplyResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportExecutionInput;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedApprovedCatalogImportArtifacts;
use App\Nutrition\Infrastructure\Catalog\Import\ApprovedCatalogImportGraphInspector;
use App\Nutrition\Infrastructure\Catalog\Import\ApprovedCatalogImportTransactionalGraphWriter;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ApplyApprovedLegacyCatalogImport
{
    public function __construct(
        private ApprovedCatalogImportGraphInspector $inspector,
        private ApprovedCatalogImportTransactionalGraphWriter $writer,
    ) {}

    public function execute(
        LoadedApprovedCatalogImportArtifacts $artifacts,
        ApprovedCatalogImportExecutionInput $input,
    ): ApprovedCatalogImportApplyResult {
        try {
            return DB::connection()->transaction(function () use ($artifacts, $input): ApprovedCatalogImportApplyResult {
                $actorExists = User::query()->select('id')->whereKey($input->actorId)->exists();

                if (! $actorExists) {
                    return new ApprovedCatalogImportApplyResult(
                        ApprovedCatalogImportOutcome::ActorInvalid,
                        'The execution actor does not identify an existing persisted user.',
                    );
                }

                $inspection = $this->inspector->inspect($artifacts);

                if ($inspection->state === ApprovedCatalogImportGraphState::Exact) {
                    return new ApprovedCatalogImportApplyResult(
                        ApprovedCatalogImportOutcome::NoOpReplay,
                        'The complete approved graph and root events already exist exactly.',
                        $inspection->graphFingerprints,
                    );
                }

                if ($inspection->state === ApprovedCatalogImportGraphState::Conflict) {
                    return new ApprovedCatalogImportApplyResult(
                        ApprovedCatalogImportOutcome::CatalogConflict,
                        'The approved graph is partially present or semantically different.',
                        $inspection->graphFingerprints,
                    );
                }

                if ($inspection->state === ApprovedCatalogImportGraphState::Drift) {
                    return new ApprovedCatalogImportApplyResult(
                        ApprovedCatalogImportOutcome::CatalogDrift,
                        'The approved graph is absent but the initial catalog snapshot has drifted.',
                        $inspection->graphFingerprints,
                    );
                }

                $this->writer->write($artifacts, $input);
                $postWriteInspection = $this->inspector->inspectPostWrite($artifacts);

                if ($postWriteInspection->state !== ApprovedCatalogImportGraphState::Exact) {
                    throw new ApprovedCatalogImportPostWriteVerificationException(
                        'The persisted graph failed post-write semantic verification.',
                    );
                }

                return new ApprovedCatalogImportApplyResult(
                    ApprovedCatalogImportOutcome::Applied,
                    'The complete approved catalog graph and ten root events were applied.',
                    $postWriteInspection->graphFingerprints,
                );
            }, attempts: 1);
        } catch (ApprovedCatalogImportPostWriteVerificationException $exception) {
            return new ApprovedCatalogImportApplyResult(
                ApprovedCatalogImportOutcome::PostWriteVerificationFailed,
                $exception->getMessage(),
            );
        } catch (Throwable) {
            return new ApprovedCatalogImportApplyResult(
                ApprovedCatalogImportOutcome::PersistenceFailed,
                'The approved catalog graph could not be persisted; the transaction was rolled back.',
            );
        }
    }
}
