<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Insights\Models\InsightsConsent;
use Lorisleiva\Actions\Concerns\AsAction;

final class MirrorInsightsConsentToPrivacyCenterAction
{
    use AsAction;

    /** @var array<string, string> */
    private const array CATEGORY_MAP = [
        'essential' => 'essential',
        'insights' => 'analytics',
        'marketing' => 'marketing',
        'preferences' => 'preferences',
    ];

    public function handle(InsightsConsent $consent): int
    {
        if (! $this->privacyCenterIsAvailable()) {
            return 0;
        }

        $recorded = 0;

        foreach (self::CATEGORY_MAP as $insightsCategory => $privacyCategory) {
            if ($this->recordConsent($consent, $insightsCategory, $privacyCategory)) {
                $recorded++;
            }
        }

        return $recorded;
    }

    private function privacyCenterIsAvailable(): bool
    {
        return CapellCore::isPackageInstalled('capell-app/privacy-center')
            && class_exists('Capell\\PrivacyCenter\\Actions\\RecordConsentAction')
            && class_exists('Capell\\PrivacyCenter\\Data\\ConsentRecordData')
            && class_exists('Capell\\PrivacyCenter\\Enums\\ConsentDecision')
            && class_exists('Capell\\PrivacyCenter\\Enums\\CookieCategory');
    }

    private function recordConsent(InsightsConsent $consent, string $insightsCategory, string $privacyCategory): bool
    {
        $cookieCategoryClass = 'Capell\\PrivacyCenter\\Enums\\CookieCategory';
        $consentDecisionClass = 'Capell\\PrivacyCenter\\Enums\\ConsentDecision';
        $consentRecordDataClass = 'Capell\\PrivacyCenter\\Data\\ConsentRecordData';
        $recordConsentActionClass = 'Capell\\PrivacyCenter\\Actions\\RecordConsentAction';

        $category = $cookieCategoryClass::tryFrom($privacyCategory);
        $decision = $consent->categories->{$insightsCategory}
            ? $consentDecisionClass::Granted
            : $consentDecisionClass::Denied;

        if ($category === null) {
            return false;
        }

        $data = new $consentRecordDataClass(
            category: $category,
            decision: $decision,
            siteId: $consent->visit?->site_id,
            policyVersion: $consent->policy_version,
            jurisdiction: $consent->consent_region->value,
            decidedAt: $consent->decided_at,
            evidence: [
                'surface' => 'insights-consent',
                'status' => $consent->status->value,
                'visit_uuid' => $consent->visit?->uuid,
            ],
            metadata: [
                'source_package' => 'capell-app/insights',
                'insights_consent_id' => $consent->getKey(),
                'insights_category' => $insightsCategory,
            ],
        );

        $recordConsentActionClass::run($data, source: $consent);

        return true;
    }
}
