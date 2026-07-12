<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static InsightsVisit run(Request $request, InsightsConsentRegion $region)
 */
final class CreateInsightsVisitAction
{
    use AsAction;

    public function handle(Request $request, InsightsConsentRegion $region): InsightsVisit
    {
        $referer = $request->headers->get('referer');

        return InsightsVisit::query()->create([
            'uuid' => (string) Str::uuid(),
            'site_id' => $this->modelKey($request->attributes->get('site'), Site::class),
            'language_id' => $this->modelKey($request->attributes->get('language'), Language::class),
            'consent_region' => $region,
            'consent_status' => InsightsConsentStatus::Pending,
            'landing_url' => $referer ?? $request->fullUrl(),
            'referrer_url' => $referer,
            'utm_source' => $this->stringInput($request, 'utm_source'),
            'utm_medium' => $this->stringInput($request, 'utm_medium'),
            'utm_campaign' => $this->stringInput($request, 'utm_campaign'),
            'ip_hash' => $this->hashVisitorValue($request->ip(), $this->modelKey($request->attributes->get('site'), Site::class)),
            'user_agent_hash' => $this->hashVisitorValue($request->userAgent(), $this->modelKey($request->attributes->get('site'), Site::class)),
            'started_at' => now()->toImmutable(),
            'last_seen_at' => now()->toImmutable(),
        ]);
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

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function hashSalt(?int $siteId): ?string
    {
        return ResolveInsightsHashSaltAction::run($siteId);
    }
}
