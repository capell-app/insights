<?php

declare(strict_types=1);

namespace Capell\Insights\Bridges\PrivacyCenter;

use Capell\Core\Facades\CapellCore;
use Capell\Insights\Models\InsightsConsent;
use Capell\PrivacyCenter\Actions\RecordConsentAction;
use Capell\PrivacyCenter\Data\ConsentRecordData;
use Capell\PrivacyCenter\Enums\ConsentDecision;
use Capell\PrivacyCenter\Enums\CookieCategory;

/** Records an Insights consent category in Privacy Center; reports nothing recorded when it is not available. */
final class PrivacyCenterConsentBridge
{
    public static function record(InsightsConsent $consent, string $insightsCategory, string $privacyCategory): bool
    {
        if (! CapellCore::isPackageAvailable('capell-app/privacy-center')) {
            return false;
        }

        $category = CookieCategory::tryFrom($privacyCategory);
        $decision = $consent->categories->{$insightsCategory}
            ? ConsentDecision::Granted
            : ConsentDecision::Denied;

        if ($category === null) {
            return false;
        }

        $data = new ConsentRecordData(
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

        RecordConsentAction::run($data, source: $consent);

        return true;
    }
}
