<?php

declare(strict_types=1);

function insightsPackagePath(string $path): string
{
    return dirname(__DIR__, 2) . '/' . $path;
}

/**
 * @return array<string, mixed>
 */
function insightsPackageJson(string $path): array
{
    $decoded = json_decode(
        (string) file_get_contents(insightsPackagePath($path)),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    throw_unless(is_array($decoded), RuntimeException::class, 'Expected Insights package JSON to decode to an array.');

    $normalized = [];

    foreach ($decoded as $key => $value) {
        throw_unless(is_string($key), RuntimeException::class, 'Expected Insights package JSON to use string keys.');

        $normalized[$key] = $value;
    }

    return $normalized;
}

it('declares installed settings and page permission surfaces', function (): void {
    $manifest = insightsPackageJson('capell.json');

    expect($manifest['settings'] ?? [])->toBe([
        'Capell\\Insights\\Settings\\InsightsSettings',
    ])->and($manifest['permissions'] ?? [])->toContain('View:InsightsPage');
});

it('keeps marketplace screenshots backed by committed assets', function (): void {
    $manifest = insightsPackageJson('capell.json');
    $marketplace = $manifest['marketplace'] ?? null;

    throw_unless(is_array($marketplace), RuntimeException::class, 'Expected Insights marketplace metadata to be an array.');

    $screenshots = $marketplace['screenshots'] ?? null;

    throw_unless(is_array($screenshots), RuntimeException::class, 'Expected Insights marketplace screenshots to be an array.');

    expect($screenshots)->toHaveCount(6);

    foreach ($screenshots as $screenshot) {
        throw_unless(is_array($screenshot), RuntimeException::class, 'Expected Insights screenshot entries to be arrays.');

        $path = $screenshot['path'] ?? null;
        $alt = $screenshot['alt'] ?? null;
        $caption = $screenshot['caption'] ?? null;

        throw_unless(is_string($path), RuntimeException::class, 'Expected Insights screenshot path to be a string.');
        throw_unless(is_string($alt), RuntimeException::class, 'Expected Insights screenshot alt text to be a string.');
        throw_unless(is_string($caption), RuntimeException::class, 'Expected Insights screenshot caption to be a string.');

        expect($screenshot)->toHaveKeys(['path', 'alt', 'caption'])
            ->and($path)->not->toBeEmpty()
            ->and($alt)->not->toBeEmpty()
            ->and($caption)->not->toBeEmpty()
            ->and(insightsPackagePath($path))->toBeFile();
    }
});
