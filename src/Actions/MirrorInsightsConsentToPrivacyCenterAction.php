<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Insights\Bridges\PrivacyCenter\PrivacyCenterConsentBridge;
use Capell\Insights\Models\InsightsConsent;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class MirrorInsightsConsentToPrivacyCenterAction
{
    use AsFake;
    use AsObject;

    /** @var array<string, string> */
    private const array CATEGORY_MAP = [
        'essential' => 'essential',
        'insights' => 'analytics',
        'marketing' => 'marketing',
        'preferences' => 'preferences',
    ];

    public function handle(InsightsConsent $consent): int
    {
        if (! CapellCore::isPackageAvailable('capell-app/privacy-center')) {
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

    private function recordConsent(InsightsConsent $consent, string $insightsCategory, string $privacyCategory): bool
    {
        return PrivacyCenterConsentBridge::record($consent, $insightsCategory, $privacyCategory);
    }
}
