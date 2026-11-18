<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Capell\Insights\Models\InsightsVisit;
use Capell\Insights\Support\InsightsUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static InsightsVisit run(Request $request, InsightsConsentRegion $region, ?string $landingUrl = null, ?string $referrerUrl = null)
 */
final class CreateInsightsVisitAction
{
    use AsFake;
    use AsObject;

    /**
     * The landing URL is the page the visitor opened (the beacon's own Referer when not given);
     * the referrer is the page that sent them, as reported by the browser.
     */
    public function handle(Request $request, InsightsConsentRegion $region, ?string $landingUrl = null, ?string $referrerUrl = null): InsightsVisit
    {
        $siteId = $this->modelKey($request->attributes->get('site'), Site::class);
        $landing = $landingUrl ?? $request->headers->get('referer') ?? $request->fullUrl();
        $utm = InsightsUrl::utmParameters($landing);

        return InsightsVisit::query()->create([
            'uuid' => (string) Str::uuid(),
            'site_id' => $siteId,
            'language_id' => $this->modelKey($request->attributes->get('language'), Language::class),
            'consent_region' => $region,
            'consent_status' => InsightsConsentStatus::Pending,
            'landing_url' => InsightsUrl::originAndPath($landing) ?? '',
            'referrer_url' => $this->externalReferrer($referrerUrl, $landing),
            'utm_source' => $utm['utm_source'],
            'utm_medium' => $utm['utm_medium'],
            'utm_campaign' => $utm['utm_campaign'],
            'ip_hash' => HashInsightsVisitorValueAction::run($request->ip(), $siteId),
            'user_agent_hash' => HashInsightsVisitorValueAction::run($request->userAgent(), $siteId),
            'visitor_hash' => $this->visitorHash($request, $siteId),
            'sample_weight' => ResolveInsightsSampleRateAction::weight(),
            'started_at' => now()->toImmutable(),
            'last_seen_at' => now()->toImmutable(),
        ]);
    }

    /**
     * One daily-salted identity per address and browser, so several visits from the same
     * browser on the same day count as one unique visitor.
     */
    private function visitorHash(Request $request, ?int $siteId): ?string
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        if ($ip === null || $ip === '' || $userAgent === null || $userAgent === '') {
            return null;
        }

        return HashInsightsVisitorValueAction::run($ip . "\n" . $userAgent, $siteId);
    }

    private function externalReferrer(?string $referrerUrl, string $landingUrl): ?string
    {
        $referrerHost = InsightsUrl::host($referrerUrl);

        if ($referrerHost === null || $referrerHost === InsightsUrl::host($landingUrl)) {
            return null;
        }

        return InsightsUrl::originAndPath($referrerUrl);
    }

    /**
     * @param  class-string<Site|Language>  $expectedClass
     */
    private function modelKey(mixed $model, string $expectedClass): ?int
    {
        if (! $model instanceof $expectedClass) {
            return null;
        }

        $key = $model->getKey();

        return is_numeric($key) ? (int) $key : null;
    }
}
