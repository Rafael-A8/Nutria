<?php

use App\Nutrition\Application\Catalog\Import\CanonicalCatalogImportJson;
use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\CatalogImportSemanticGraphFingerprint;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportLifecycleIdempotencyInput;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportManifestSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportSemanticGraph;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;

function lifecycleImportInputM242(
    string $actorId = '1',
    string $actorReference = 'audit:user:1',
    ?string $reason = null,
    string $occurredAt = '2026-07-20T15:00:00.000000Z',
): CatalogImportLifecycleIdempotencyInput {
    return new CatalogImportLifecycleIdempotencyInput(
        manifestChecksum: new CanonicalManifestChecksum('sha256', str_repeat('0', 64)),
        subjectType: CatalogLifecycleSubjectType::ReferenceVersion,
        subjectPublicId: '9d04432d-a814-5f9e-af1f-0165c6fc8dcf',
        operation: CatalogLifecycleOperation::CreateDraft,
        actorId: $actorId,
        actorReference: $actorReference,
        reason: $reason,
        occurredAt: new DateTimeImmutable($occurredAt),
    );
}

function semanticImportGraphM242(bool $reverseAliases = false, string $stableKey = 'dairy-condensed-milk'): CatalogImportSemanticGraph
{
    $aliases = [
        [
            'lineage_id' => '11111111-1111-5111-8111-111111111111',
            'public_id' => '22222222-2222-5222-8222-222222222222',
            'normalized_alias' => 'leite condensado',
        ],
        [
            'lineage_id' => '33333333-3333-5333-8333-333333333333',
            'public_id' => '44444444-4444-5444-8444-444444444444',
            'normalized_alias' => 'leite condesado',
        ],
    ];

    return new CatalogImportSemanticGraph(
        source: ['public_id' => 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907', 'authority' => 'untrusted'],
        reference: ['public_id' => '6e360841-51b8-5deb-a201-38debd0f03dc', 'stable_key' => $stableKey],
        version: ['public_id' => '9d04432d-a814-5f9e-af1f-0165c6fc8dcf', 'review_status' => 'draft'],
        sourceLink: ['role' => 'primary', 'source_record_key' => 'leite condensado'],
        aliases: $reverseAliases ? array_reverse($aliases) : $aliases,
        initialLifecycleStates: ['reference' => 'available', 'source' => 'available', 'version' => 'draft'],
        provenance: ['artifact_id' => 'legacy_config_nutrition_v1', 'source_record_key' => 'leite condensado'],
    );
}

it('serializes canonical manifest JSON with deterministic semantic ordering', function () {
    $first = [
        'source' => ['artifact_path' => 'config/nutrition.php', 'enabled' => true],
        'records' => [
            [
                'source_record_key' => 'zeta',
                'ordered_steps' => [2, 1],
                'issue_codes' => ['alias_kind_unresolved', 'structural_shape_invalid'],
                'aliases' => [
                    ['normalized_alias' => 'zeta'],
                    ['normalized_alias' => 'alpha'],
                ],
                'values' => [1, 1.0, 12.5, false, null],
            ],
            ['source_record_key' => 'açaí', 'values' => [2, true]],
        ],
        'schema' => CatalogImportManifestSchema::IDENTIFIER,
    ];
    $second = $first;
    $second['records'] = array_reverse($second['records']);
    $second['records'][1]['aliases'] = array_reverse($second['records'][1]['aliases']);
    $second['records'][1]['issue_codes'] = array_reverse($second['records'][1]['issue_codes']);
    $second['source'] = ['enabled' => true, 'artifact_path' => 'config/nutrition.php'];

    $expected = '{"records":[{"source_record_key":"açaí","values":[2,true]},{"aliases":[{"normalized_alias":"alpha"},{"normalized_alias":"zeta"}],"issue_codes":["structural_shape_invalid","alias_kind_unresolved"],"ordered_steps":[2,1],"source_record_key":"zeta","values":[1,1.0,12.5,false,null]}],"schema":"nutria.catalog-import-manifest/1","source":{"artifact_path":"config/nutrition.php","enabled":true}}';
    $canonicalFirst = CanonicalCatalogImportJson::serializeManifest($first);
    $canonicalSecond = CanonicalCatalogImportJson::serializeManifest($second);

    expect($canonicalFirst)->toBe($expected)
        ->and($canonicalSecond)->toBe($expected)
        ->and($canonicalFirst)->toContain('açaí', 'config/nutrition.php')
        ->and($canonicalFirst)->not->toContain('\\u', 'config\\/nutrition.php', "\n", '  ');
});

it('rejects self-referential checksum, execution data, and duplicate records', function (array $manifest) {
    expect(fn () => CanonicalCatalogImportJson::serializeManifest($manifest))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'self-referential checksum' => [[
        'schema' => CatalogImportManifestSchema::IDENTIFIER,
        'manifest_checksum' => str_repeat('0', 64),
    ]],
    'execution timestamp' => [[
        'schema' => CatalogImportManifestSchema::IDENTIFIER,
        'generated_at' => '2026-07-20T15:00:00Z',
    ]],
    'machine path' => [[
        'schema' => CatalogImportManifestSchema::IDENTIFIER,
        'machine_path' => '/home/user/manifest.json',
    ]],
    'duplicate records' => [[
        'schema' => CatalogImportManifestSchema::IDENTIFIER,
        'records' => [
            ['source_record_key' => 'same'],
            ['source_record_key' => 'same'],
        ],
    ]],
]);

it('rejects invalid UTF-8 and nonfinite canonical values', function (mixed $value) {
    expect(fn () => CanonicalCatalogImportJson::serializeManifest([
        'schema' => CatalogImportManifestSchema::IDENTIFIER,
        'value' => $value,
    ]))->toThrow(InvalidArgumentException::class);
})->with([
    'invalid UTF-8' => "\xB1\x31",
    'positive infinity' => INF,
    'not a number' => NAN,
]);

it('freezes the UUIDv5 namespace hierarchy', function () {
    expect(CatalogImportDeterministicIdentity::ROOT_NAMESPACE)->toBe('d83e65a3-da9f-5ff1-9c84-29ab6c208724')
        ->and(CatalogImportDeterministicIdentity::SOURCE_NAMESPACE)->toBe('3f41222a-363c-5994-add1-9e8b4bdbfe8d')
        ->and(CatalogImportDeterministicIdentity::REFERENCE_NAMESPACE)->toBe('7969d0f8-abc6-5f96-b381-a542b66fe2fd')
        ->and(CatalogImportDeterministicIdentity::REFERENCE_VERSION_NAMESPACE)->toBe('a5aa9049-cf99-5362-96de-0f08aa4e5f77')
        ->and(CatalogImportDeterministicIdentity::ALIAS_LINEAGE_NAMESPACE)->toBe('021118d0-af97-5350-8bfd-d769e5631fbd')
        ->and(CatalogImportDeterministicIdentity::ALIAS_REVISION_NAMESPACE)->toBe('1765715a-577d-59ee-a6bd-884dde00e135')
        ->and(CatalogImportDeterministicIdentity::LIFECYCLE_IDEMPOTENCY_NAMESPACE)->toBe('08897a45-0e20-560e-9226-344391496c79');
});

it('calculates non-ASCII UUID component lengths from UTF-8 bytes', function () {
    $value = 'café';
    $parameters = (new ReflectionMethod(
        CatalogImportDeterministicIdentity::class,
        'canonicalComponent',
    ))->getParameters();

    expect(mb_strlen($value, 'UTF-8'))->toBe(4)
        ->and(strlen($value))->toBe(5)
        ->and(CatalogImportDeterministicIdentity::canonicalComponent($value))->toBe('5:café')
        ->and($parameters)->toHaveCount(1)
        ->and($parameters[0]->getName())->toBe('value');
});

it('matches every corrected leite condensado canonical name and UUID', function () {
    $sourceName = CatalogImportDeterministicIdentity::sourceCanonicalName();
    $source = CatalogImportDeterministicIdentity::sourcePublicId();
    $referenceName = CatalogImportDeterministicIdentity::plannedReferenceCanonicalName('leite condensado');
    $reference = CatalogImportDeterministicIdentity::plannedReferencePublicId('leite condensado');
    $versionName = CatalogImportDeterministicIdentity::referenceVersionCanonicalName($reference, 1);
    $version = CatalogImportDeterministicIdentity::referenceVersionPublicId($reference, 1);
    $lineageName = CatalogImportDeterministicIdentity::aliasLineageCanonicalName($reference, 'pt-BR', 'leite condensado');
    $lineage = CatalogImportDeterministicIdentity::aliasLineageId($reference, 'pt-BR', 'leite condensado');
    $revisionName = CatalogImportDeterministicIdentity::aliasRevisionCanonicalName($lineage, 1);
    $revision = CatalogImportDeterministicIdentity::aliasRevisionPublicId($lineage, 1);

    expect($sourceName)->toBe('v1|artifact|26:legacy_config_nutrition_v1')
        ->and($source)->toBe('ead17ec3-6176-5f48-b25c-6f4ce3ce9907')
        ->and($referenceName)->toBe('v1|artifact|26:legacy_config_nutrition_v1|record_key|16:leite condensado')
        ->and($reference)->toBe('6e360841-51b8-5deb-a201-38debd0f03dc')
        ->and($versionName)->toBe('v1|reference|36:6e360841-51b8-5deb-a201-38debd0f03dc|version|1')
        ->and($version)->toBe('9d04432d-a814-5f9e-af1f-0165c6fc8dcf')
        ->and($lineageName)->toBe('v1|reference|36:6e360841-51b8-5deb-a201-38debd0f03dc|locale|5:pt-BR|normalized_alias|16:leite condensado')
        ->and($lineage)->toBe('63036985-520e-5cbb-8970-74fe9cb41d18')
        ->and($revisionName)->toBe('v1|lineage|36:63036985-520e-5cbb-8970-74fe9cb41d18|revision|1')
        ->and($revision)->toBe('c8a9ff8f-7cdf-578f-a372-109b36c5d880');
});

it('matches the corrected lifecycle canonical name and UUID', function () {
    $input = lifecycleImportInputM242();
    $expectedName = 'v1|manifest_sha256|64:'.str_repeat('0', 64)
        .'|subject_type|17:reference_version'
        .'|subject_public_id|36:9d04432d-a814-5f9e-af1f-0165c6fc8dcf'
        .'|operation|12:create_draft'
        .'|actor_id|1:1'
        .'|actor_reference|12:audit:user:1'
        .'|reason|null'
        .'|occurred_at|27:2026-07-20T15:00:00.000000Z';

    expect(CatalogImportDeterministicIdentity::lifecycleIdempotencyCanonicalName($input))->toBe($expectedName)
        ->and(CatalogImportDeterministicIdentity::lifecycleIdempotencyKey($input))
        ->toBe('319ac3fb-b813-5e68-85ce-59cf57ac4900');
});

it('makes lifecycle idempotency sensitive to inherited execution fields', function () {
    $baseline = CatalogImportDeterministicIdentity::lifecycleIdempotencyKey(lifecycleImportInputM242());

    expect(CatalogImportDeterministicIdentity::lifecycleIdempotencyKey(lifecycleImportInputM242(actorId: '2')))->not->toBe($baseline)
        ->and(CatalogImportDeterministicIdentity::lifecycleIdempotencyKey(lifecycleImportInputM242(actorReference: 'audit:user:2')))->not->toBe($baseline)
        ->and(CatalogImportDeterministicIdentity::lifecycleIdempotencyKey(lifecycleImportInputM242(reason: 'reviewed')))->not->toBe($baseline)
        ->and(CatalogImportDeterministicIdentity::lifecycleIdempotencyKey(lifecycleImportInputM242(occurredAt: '2026-07-20T15:00:00.000001Z')))->not->toBe($baseline);
});

it('normalizes lifecycle reason before creating its canonical input', function () {
    $trimmed = lifecycleImportInputM242(reason: 'reviewed');
    $untrimmed = lifecycleImportInputM242(reason: '  reviewed  ');

    expect($untrimmed->normalizedReason)->toBe('reviewed')
        ->and(CatalogImportDeterministicIdentity::lifecycleIdempotencyKey($untrimmed))
        ->toBe(CatalogImportDeterministicIdentity::lifecycleIdempotencyKey($trimmed));
});

it('fingerprints semantic graph aliases as an unordered set', function () {
    $graph = semanticImportGraphM242();
    $reorderedAliases = semanticImportGraphM242(reverseAliases: true);
    $fingerprint = CatalogImportSemanticGraphFingerprint::forGraph($graph);

    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and(CatalogImportSemanticGraphFingerprint::forGraph($reorderedAliases))->toBe($fingerprint)
        ->and(CatalogImportSemanticGraphFingerprint::forGraph(
            semanticImportGraphM242(stableKey: 'different-concept'),
        ))->not->toBe($fingerprint);
});

it('keeps actor and occurred-at outside semantic graph fingerprint inputs', function () {
    $constructorParameterNames = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass(CatalogImportSemanticGraph::class))->getConstructor()?->getParameters() ?? [],
    );
    $fingerprintParameters = (new ReflectionMethod(CatalogImportSemanticGraphFingerprint::class, 'forGraph'))->getParameters();

    expect($constructorParameterNames)->not->toContain(
        'internalId',
        'databaseTimestamp',
        'eventPublicId',
        'actorId',
        'actorReference',
        'occurredAt',
    )->and($fingerprintParameters)->toHaveCount(1)
        ->and($fingerprintParameters[0]->getType()?->getName())->toBe(CatalogImportSemanticGraph::class)
        ->and(fn () => CanonicalCatalogImportJson::serializeSemanticGraph([
            'source' => ['actor_id' => '1'],
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CanonicalCatalogImportJson::serializeSemanticGraph([
            'version' => ['occurred_at' => '2026-07-20T15:00:00.000000Z'],
        ]))->toThrow(InvalidArgumentException::class);
});
