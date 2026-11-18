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

it('rotates the salt per site at each local midnight', function (): void {
    config()->set('capell-insights.hash_salt', 'private-insights-salt');

    $morning = ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01 00:00:01', 'UTC'));
    $evening = ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01 23:59:59', 'UTC'));
    $otherSite = ResolveInsightsHashSaltAction::run(2, CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC'));
    $nextDay = ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-02 00:00:01', 'UTC'));

    expect($morning)->toBe($evening)
        ->and($morning)->not->toBe($otherSite)
        ->and($morning)->not->toBe($nextDay);
});

it('starts the salt day at midnight in the site timezone', function (): void {
    config()->set('capell-insights.hash_salt', 'private-insights-salt');
    config()->set('capell-insights.site_timezones', [1 => 'America/New_York']);

    // 02:00 UTC on 2 July is still 1 July in New York, so it shares the 1 July salt.
    $lateLocalEvening = ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-02 02:00:00', 'UTC'));
    $localAfternoon = ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-01 18:00:00', 'UTC'));
    $localNextMorning = ResolveInsightsHashSaltAction::run(1, CarbonImmutable::parse('2026-07-02 05:00:00', 'UTC'));

    expect($lateLocalEvening)->toBe($localAfternoon)
        ->and($lateLocalEvening)->not->toBe($localNextMorning);
});
