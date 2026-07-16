<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsConsentData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class UpdateInsightsConsentAction
{
    use AsFake;
    use AsObject;

    public function handle(
        Request $request,
        InsightsConsentData $data,
        InsightsConsentStatus $status,
        InsightsConsentRegion $region,
    ): InsightsConsent {
        if ($status === InsightsConsentStatus::Granular && ! $request->boolean('terms_accepted')) {
            throw ValidationException::withMessages([
                'terms_accepted' => __('validation.accepted', ['attribute' => 'terms accepted']),
            ]);
        }

        $visit = $this->resolveVisit($request, $region);
        $acceptedTerms = $request->boolean('terms_accepted');

        $consent = InsightsConsent::query()->create([
            'visit_id' => $visit->getKey(),
            'consent_region' => $region,
            'status' => $status,
            'categories' => $data,
            'policy_version' => $this->policyVersion(),
            'terms_accepted_at' => $acceptedTerms ? now()->toImmutable() : null,
            'decided_at' => now()->toImmutable(),
            'ip_hash' => $this->hashVisitorValue($request->ip(), $visit->site_id),
            'user_agent_hash' => $this->hashVisitorValue($request->userAgent(), $visit->site_id),
        ]);

        $visit->forceFill([
            'consent_region' => $region,
            'consent_status' => $status,
            'last_seen_at' => now()->toImmutable(),
        ])->save();

        Cookie::queue('capell_insights_visit', $visit->uuid, 60 * 24 * 365);

        $consent->load('visit');

        MirrorInsightsConsentToPrivacyCenterAction::run($consent);

        RememberInsightsDashboardAggregateAction::flush();

        return $consent;
    }

    private function resolveVisit(Request $request, InsightsConsentRegion $region): InsightsVisit
    {
        $visitUuid = $request->cookie('capell_insights_visit');

        if (is_string($visitUuid) && $visitUuid !== '') {
            $visit = InsightsVisit::query()
                ->where('uuid', $visitUuid)
                ->first();

            if ($visit instanceof InsightsVisit) {
                return $visit;
            }
        }

        return CreateInsightsVisitAction::run($request, $region);
    }

    private function hashVisitorValue(?string $value, ?int $siteId): ?string
    {
        if (config('capell-insights.hash_visitor_data', true) !== true) {
            return null;
        }

        if ($value === null || trim($value) === '') {
            return null;
        }

        $salt = $this->hashSalt($siteId);

        return $salt === null ? null : hash_hmac('sha256', $value, $salt);
    }

    private function policyVersion(): string
    {
        $policyVersion = config('capell-insights.policy_version', '1.0');

        return is_string($policyVersion) && $policyVersion !== '' ? $policyVersion : '1.0';
    }

    private function hashSalt(?int $siteId): ?string
    {
        return ResolveInsightsHashSaltAction::run($siteId);
    }
}
