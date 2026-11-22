<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Whether a visit's region and consent record allow measurement. Insights applies it before
 * storing an event, and any package that stores data about a visit must apply the same rule
 * so a visitor who declined analytics is not recorded elsewhere.
 *
 * @method static bool run(InsightsVisit $visit)
 */
final class CanRecordForInsightsVisitAction
{
    use AsFake;
    use AsObject;

    public function handle(InsightsVisit $visit): bool
    {
        if ($this->canRecordForRegion($visit->consent_region)) {
            return true;
        }

        if ($visit->consent_region === InsightsConsentRegion::UkOrEurope
            || $visit->consent_region === InsightsConsentRegion::Unknown
            || ResolveInsightsRuntimeSettingsAction::run()->requireConsentForAllRegions) {
            return $this->hasInsightsConsent($visit);
        }

        return true;
    }

    public function canRecordForRegion(InsightsConsentRegion $region): bool
    {
        return ! ResolveInsightsRuntimeSettingsAction::run()->requireConsentForAllRegions
            && $region === InsightsConsentRegion::OutsideUkOrEurope;
    }

    public function latestConsent(InsightsVisit $visit): ?InsightsConsent
    {
        return $visit->consents()
            ->latest('decided_at')
            ->first();
    }

    private function hasInsightsConsent(InsightsVisit $visit): bool
    {
        $latestConsent = $this->latestConsent($visit);

        if ($latestConsent instanceof InsightsConsent) {
            return $latestConsent->categories->insights
                && $this->consentMatchesCurrentPolicy($latestConsent)
                && ! $this->consentHasExpired($latestConsent);
        }

        return $visit->consent_status === InsightsConsentStatus::AcceptedAll;
    }

    private function consentMatchesCurrentPolicy(InsightsConsent $consent): bool
    {
        return hash_equals(ResolveInsightsRuntimeSettingsAction::run()->policyVersion, $consent->policy_version);
    }

    private function consentHasExpired(InsightsConsent $consent): bool
    {
        $expiresDays = config('capell-insights.consent_expires_days', 180);

        if ($expiresDays === null || $expiresDays === false) {
            return false;
        }

        if (! is_numeric($expiresDays) || (int) $expiresDays < 1) {
            return false;
        }

        if (! $consent->decided_at instanceof CarbonImmutable) {
            return true;
        }

        return $consent->decided_at->addDays((int) $expiresDays)->isPast();
    }
}
