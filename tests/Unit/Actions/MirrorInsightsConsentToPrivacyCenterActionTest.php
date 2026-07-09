<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Insights\Actions\MirrorInsightsConsentToPrivacyCenterAction;
use Capell\Insights\Data\InsightsConsentData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('does nothing when privacy center is not installed', function (): void {
    CapellCore::forcePackageInstalled('capell-app/privacy-center', false);

    $visit = InsightsVisit::factory()->create();
    $consent = InsightsConsent::factory()->create([
        'visit_id' => $visit->getKey(),
        'categories' => new InsightsConsentData(insights: true),
    ]);

    $recorded = MirrorInsightsConsentToPrivacyCenterAction::run($consent->load('visit'));

    expect($recorded)->toBe(0);
});

it('mirrors insights consent categories into privacy center records when installed', function (): void {
    CapellCore::forcePackageInstalled('capell-app/privacy-center');

    (require __DIR__ . '/../../../../privacy-center/database/migrations/2026_05_31_000001_create_privacy_consent_policies_table.php')->up();
    (require __DIR__ . '/../../../../privacy-center/database/migrations/2026_05_31_000002_create_privacy_consent_records_table.php')->up();

    $visit = InsightsVisit::factory()->create([
        'site_id' => null,
    ]);
    $consent = InsightsConsent::factory()->create([
        'visit_id' => $visit->getKey(),
        'consent_region' => InsightsConsentRegion::UkOrEurope,
        'status' => InsightsConsentStatus::Granular,
        'categories' => new InsightsConsentData(
            insights: true,
            marketing: false,
            preferences: true,
        ),
        'policy_version' => '2026-05-31',
    ]);

    $recorded = MirrorInsightsConsentToPrivacyCenterAction::run($consent->load('visit'));

    expect($recorded)->toBe(4)
        ->and(Schema::hasTable('privacy_consent_records'))->toBeTrue()
        ->and(DB::table('privacy_consent_records')->where('category', 'essential')->value('decision'))->toBe('granted')
        ->and(DB::table('privacy_consent_records')->where('category', 'analytics')->value('decision'))->toBe('granted')
        ->and(DB::table('privacy_consent_records')->where('category', 'marketing')->value('decision'))->toBe('denied')
        ->and(DB::table('privacy_consent_records')->where('category', 'preferences')->value('decision'))->toBe('granted')
        ->and(DB::table('privacy_consent_records')->where('category', 'analytics')->value('source_type'))->toBe('insights_consent');
});
