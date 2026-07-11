<?php

use App\Nutrition\Application\Catalog\NormalizeFoodText;
use App\Nutrition\Domain\Catalog\ValueObjects\NormalizedFoodText;

it('normalizes food text deterministically', function (string $foodText, string $expected) {
    $normalized = (new NormalizeFoodText)->normalize($foodText);

    expect($normalized)
        ->toBeInstanceOf(NormalizedFoodText::class)
        ->and($normalized->original)->toBe($foodText)
        ->and($normalized->value)->toBe($expected);
})->with([
    'accented lowercase' => ['Feijão', 'feijao'],
    'already normalized' => ['feijao', 'feijao'],
    'hyphen' => ['grão-de-bico', 'grao de bico'],
    'uppercase and repeated whitespace' => ['  PÃO   FRANCÊS  ', 'pao frances'],
    'slash' => ['arroz/branco', 'arroz branco'],
    'underscore' => ['peito_de_frango', 'peito de frango'],
    'punctuation and symbols' => ['Café, leite & açúcar!', 'cafe leite acucar'],
    'new lines and tabs' => ["  arroz\n\tbranco  ", 'arroz branco'],
]);

it('is idempotent', function (string $foodText) {
    $normalizer = new NormalizeFoodText;
    $first = $normalizer->normalize($foodText);
    $second = $normalizer->normalize($first->value);

    expect($second->value)->toBe($first->value);
})->with([
    'accent and hyphen' => 'Grão-de-Bico',
    'punctuation' => 'PÃO / francês!!!',
    'whitespace' => " arroz\t branco ",
]);

it('does not infer morphological or semantic variants', function (string $foodText, string $expected) {
    expect((new NormalizeFoodText)->normalize($foodText)->value)->toBe($expected);
})->with([
    'singular remains singular' => ['banana', 'banana'],
    'plural remains plural' => ['bananas', 'bananas'],
    'inflected word is not stemmed' => ['cozidas', 'cozidas'],
    'food text is not translated' => ['black beans', 'black beans'],
    'similar spelling is not corrected' => ['bananna', 'bananna'],
]);

it('rejects invalid UTF-8', function () {
    expect(fn () => (new NormalizeFoodText)->normalize("\xB1\x31"))
        ->toThrow(InvalidArgumentException::class);
});

it('returns an immutable normalized food text value object', function () {
    $reflection = new ReflectionClass(NormalizedFoodText::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
