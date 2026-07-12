<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Insights\Actions\CreateInsightsVisitAction;
use Capell\Insights\Enums\InsightsConsentRegion;
use Illuminate\Http\Request;

it('derives visit provenance from trusted request context instead of client input', function (): void {
    $site = Site::factory()->create();
    $language = Language::factory()->create();
    $request = Request::create('/', 'POST', [
        'site_id' => 999,
        'language_id' => 998,
    ], [], [], [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'Capell Test Browser',
    ]);
    $request->attributes->set('site', $site);
    $request->attributes->set('language', $language);

    $visit = CreateInsightsVisitAction::run($request, InsightsConsentRegion::OutsideUkOrEurope);

    expect($visit->site_id)->toBe($site->getKey())
        ->and($visit->language_id)->toBe($language->getKey())
        ->and($visit->site_id)->not->toBe(999)
        ->and($visit->ip_hash)->toHaveLength(64);
});
