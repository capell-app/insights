<?php

declare(strict_types=1);

use Capell\Insights\Actions\ResolveInsightsHashSaltAction;

it('uses a configured private insights hash salt when present', function (): void {
    config()->set('capell-insights.hash_salt', ' private-insights-salt ');
    config()->set('app.key', 'base64:application-key');

    expect(ResolveInsightsHashSaltAction::run())->toBe('private-insights-salt');
});

it('derives the insights hash salt from the application key by default', function (): void {
    config()->set('capell-insights.hash_salt');
    config()->set('app.key', 'base64:application-key');

    expect(ResolveInsightsHashSaltAction::run())
        ->toBe(hash_hmac('sha256', 'capell-insights', 'base64:application-key'));
});

it('ignores the public legacy salt when an application key is available', function (): void {
    config()->set('capell-insights.hash_salt', ' capell-insights ');
    config()->set('app.key', 'base64:application-key');

    expect(ResolveInsightsHashSaltAction::run())
        ->toBe(hash_hmac('sha256', 'capell-insights', 'base64:application-key'));
});

it('falls back to the legacy salt only when no usable application key exists', function (?string $applicationKey): void {
    config()->set('capell-insights.hash_salt', ' ');
    config()->set('app.key', $applicationKey);

    expect(ResolveInsightsHashSaltAction::run())->toBe('capell-insights');
})->with([
    'missing app key' => null,
    'empty app key' => '',
    'placeholder base64 app key' => 'base64:',
]);
