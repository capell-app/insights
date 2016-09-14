<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsConsentPolicyData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveInsightsConsentPolicyAction
{
    use AsFake;
    use AsObject;

    public function handle(): InsightsConsentPolicyData
    {
        return new InsightsConsentPolicyData(
            consentRequired: config('capell-insights.require_consent_for_all_regions', false) === true
                || ResolveConsentRegionAction::run() !== InsightsConsentRegion::OutsideUkOrEurope,
        );
    }
}
