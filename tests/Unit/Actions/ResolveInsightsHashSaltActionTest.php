<?php

declare(strict_types=1);

use Capell\Insights\Actions\ResolveInsightsHashSaltAction;
use Carbon\CarbonImmutable;

it('uses a configured private insights hash salt when present', function (): void {
    config()->set('capell-insights.hash_salt', ' private-insights-salt ');
    config()->set('app.key', 'base64:application-key');

    expect(ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01')))
        ->not->toBe('private-insights-salt');
});

it('derives the insights hash salt from the application key by default', function (): void {
    config()->set('capell-insights.hash_salt');
    config()->set('app.key', 'base64:application-key');

    expect(ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01')))
        ->toBe(ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01')));
});

it('ignores the public legacy salt when an application key is available', function (): void {
    config()->set('capell-insights.hash_salt', ' capell-insights ');
    config()->set('app.key', 'base64:application-key');

    expect(ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01')))
        ->toBe(ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01')));
});

it('falls back to the legacy salt only when no usable application key exists', function (?string $applicationKey): void {
    config()->set('capell-insights.hash_salt', ' ');
    config()->set('app.key', $applicationKey);

    expect(ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01')))->toBeNull();
})->with([
    'missing app key' => null,
    'empty app key' => '',
    'placeholder base64 app key' => 'base64:',
]);

it('rotates hashes by site and configured period', function (): void {
    config()->set('capell-insights.hash_salt', 'private-insights-salt');
    config()->set('capell-insights.hash_rotation_days', 30);

    $first = ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01'));
    $samePeriod = ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-15'));
    $otherSite = ResolveInsightsHashSaltAction::run(2, CarbonImmutable::parse('2026-07-15'));
    $nextPeriod = ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-08-01'));

    expect($first)->toBe($samePeriod)
        ->and($first)->not->toBe($otherSite)
        ->and($first)->not->toBe($nextPeriod);
});
