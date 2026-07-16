<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Models\Site;
use Capell\Insights\Data\InsightsEventData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, InsightsEvent> run(?string $visitUuid, iterable<int, array{data: InsightsEventData, occurred_at: string|null}> $events, ?Request $request = null, ?InsightsConsentRegion $consentRegion = null, bool $clampOccurredAt = false, bool $startNewSession = true)
 */
final class RecordInsightsEventsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  iterable<int, array{data: InsightsEventData, occurred_at: string|null}>  $events
     * @return Collection<int, InsightsEvent>
     */
    public function handle(?string $visitUuid, iterable $events, ?Request $request = null, ?InsightsConsentRegion $consentRegion = null, bool $clampOccurredAt = false, bool $startNewSession = true): Collection
    {
        if (config('capell-insights.enabled', true) !== true) {
            return collect();
        }

        if ($request instanceof Request && $this->shouldIgnoreRequest($request)) {
            return collect();
        }

        return DB::transaction(function () use ($visitUuid, $events, $request, $consentRegion, $clampOccurredAt, $startNewSession): Collection {
            $recordableEvents = $this->recordableEvents($events);

            if ($recordableEvents === []) {
                return collect();
            }

            $visit = $this->resolveVisit($visitUuid, $request, $consentRegion, $startNewSession);

            if (! $visit instanceof InsightsVisit || ! $this->canRecordForVisit($visit)) {
                return collect();
            }

            $now = now()->toImmutable();
            $maxSequence = $visit->events()->max('sequence');
            $sequence = is_numeric($maxSequence) ? ((int) $maxSequence) + 1 : 1;
            $eventRows = [];

            foreach ($recordableEvents as $event) {
                $eventData = $event['data'];

                $eventRows[] = [
                    'visit_id' => $visit->getKey(),
                    'site_id' => $visit->site_id,
                    'language_id' => $visit->language_id,
                    'type' => $eventData->type->value,
                    'url' => $eventData->url,
                    'path' => $eventData->path(),
                    'title' => $eventData->title,
                    'occurred_at' => $this->occurredAt($event['occurred_at'], $clampOccurredAt),
                    'sequence' => $sequence,
                    'event_name' => $eventData->eventName,
                    'label' => $eventData->label,
                    'location' => $eventData->location,
                    'target_selector' => $eventData->targetSelector,
                    'viewport_x' => $eventData->viewportX,
                    'viewport_y' => $eventData->viewportY,
                    'document_x' => $eventData->documentX,
                    'document_y' => $eventData->documentY,
                    'metadata' => $eventData->metadata !== null ? json_encode($eventData->metadata->toArray()) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $sequence++;
            }

            DB::table((new InsightsEvent)->getTable())->insert($eventRows);

            $visit->forceFill([
                'last_seen_at' => $now,
            ])->save();

            return $visit->events()
                ->where('sequence', '>=', $eventRows[0]['sequence'])
                ->where('sequence', '<=', $eventRows[array_key_last($eventRows)]['sequence'])
                ->orderBy('sequence')
                ->get()
                ->values();
        });
    }

    public function shouldIgnoreRequest(Request $request): bool
    {
        if ($this->isIgnoredIp($request->ip())) {
            return true;
        }

        return $this->isIgnoredUserAgent($request->userAgent());
    }

    private function resolveVisit(?string $visitUuid, ?Request $request, ?InsightsConsentRegion $consentRegion, bool $startNewSession): ?InsightsVisit
    {
        if ($visitUuid !== null && trim($visitUuid) !== '') {
            $visit = InsightsVisit::query()
                ->where('uuid', $visitUuid)
                ->lockForUpdate()
                ->first();

            if ($visit instanceof InsightsVisit && $visit->site_id !== null && $request instanceof Request && ! $this->visitBelongsToTrustedSite($visit, $request)) {
                return null;
            }

            if ($startNewSession && $visit instanceof InsightsVisit && $request instanceof Request && $this->hasExpiredSession($visit)) {
                return $this->startNextSessionVisit($visit, $request);
            }

            return $visit;
        }

        if (! $request instanceof Request || ! $this->hasTrustedSite($request) || ! $consentRegion instanceof InsightsConsentRegion || ! $this->canRecordForRegion($consentRegion)) {
            return null;
        }

        $visit = CreateInsightsVisitAction::run($request, $consentRegion);

        Cookie::queue('capell_insights_visit', $visit->uuid, 60 * 24 * 365);

        return $visit;
    }

    private function hasExpiredSession(InsightsVisit $visit): bool
    {
        if (! $visit->last_seen_at instanceof CarbonImmutable) {
            return false;
        }

        $configuredTimeoutMinutes = config('capell-insights.session_timeout_minutes', 30);
        $timeoutMinutes = is_numeric($configuredTimeoutMinutes) ? (int) $configuredTimeoutMinutes : 30;

        if ($timeoutMinutes < 1) {
            return false;
        }

        return $visit->last_seen_at->addMinutes($timeoutMinutes)->isPast();
    }

    private function startNextSessionVisit(InsightsVisit $previousVisit, Request $request): InsightsVisit
    {
        $visit = CreateInsightsVisitAction::run($request, $previousVisit->consent_region);

        $visit->forceFill([
            'consent_status' => $previousVisit->consent_status,
        ])->save();

        Cookie::queue('capell_insights_visit', $visit->uuid, 60 * 24 * 365);

        return $visit;
    }

    /**
     * @param  iterable<int, array{data: InsightsEventData, occurred_at: string|null}>  $events
     * @return list<array{data: InsightsEventData, occurred_at: string|null}>
     */
    private function recordableEvents(iterable $events): array
    {
        $recordableEvents = [];

        foreach ($events as $event) {
            if ($this->isIgnoredPath($event['data']->path())) {
                continue;
            }

            $recordableEvents[] = $event;
        }

        return $recordableEvents;
    }

    private function isIgnoredPath(string $path): bool
    {
        if ($this->isAssetPath($path)) {
            return true;
        }

        $ignoredPaths = config('capell-insights.ignored_paths', []);

        if (! is_array($ignoredPaths)) {
            return false;
        }

        foreach ($ignoredPaths as $ignoredPath) {
            if (is_string($ignoredPath) && Str::is($ignoredPath, $path)) {
                return true;
            }
        }

        return false;
    }

    private function isIgnoredIp(?string $ipAddress): bool
    {
        if ($ipAddress === null || trim($ipAddress) === '') {
            return false;
        }

        $ignoredIps = config('capell-insights.ignored_ips', []);

        if (! is_array($ignoredIps)) {
            return false;
        }

        foreach ($ignoredIps as $ignoredIp) {
            if (is_string($ignoredIp) && Str::is($ignoredIp, $ipAddress)) {
                return true;
            }
        }

        return false;
    }

    private function isIgnoredUserAgent(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return false;
        }

        $ignoredUserAgents = config('capell-insights.ignored_user_agents', []);

        if (! is_array($ignoredUserAgents)) {
            return false;
        }

        foreach ($ignoredUserAgents as $ignoredUserAgent) {
            if (is_string($ignoredUserAgent) && Str::is(strtolower($ignoredUserAgent), strtolower($userAgent))) {
                return true;
            }
        }

        return false;
    }

    private function isAssetPath(string $path): bool
    {
        return preg_match('/\.(?:css|js|map|json|xml|txt|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|pdf|zip)$/i', $path) === 1;
    }

    private function canRecordForVisit(InsightsVisit $visit): bool
    {
        if ($this->canRecordForRegion($visit->consent_region)) {
            return true;
        }

        if ($visit->consent_region === InsightsConsentRegion::UkOrEurope
            || $visit->consent_region === InsightsConsentRegion::Unknown
            || config('capell-insights.require_consent_for_all_regions', false) === true) {
            return $this->hasInsightsConsent($visit);
        }

        return true;
    }

    private function canRecordForRegion(InsightsConsentRegion $region): bool
    {
        return config('capell-insights.require_consent_for_all_regions', false) !== true
            && $region === InsightsConsentRegion::OutsideUkOrEurope;
    }

    private function hasInsightsConsent(InsightsVisit $visit): bool
    {
        $latestConsent = $visit->consents()
            ->latest('decided_at')
            ->first();

        if ($latestConsent instanceof InsightsConsent) {
            return $latestConsent->categories->insights
                && $this->consentMatchesCurrentPolicy($latestConsent)
                && ! $this->consentHasExpired($latestConsent);
        }

        return $visit->consent_status === InsightsConsentStatus::AcceptedAll;
    }

    private function consentMatchesCurrentPolicy(InsightsConsent $consent): bool
    {
        $policyVersion = config('capell-insights.policy_version', '1.0');
        $currentPolicyVersion = is_string($policyVersion) && $policyVersion !== '' ? $policyVersion : '1.0';

        return hash_equals($currentPolicyVersion, $consent->policy_version);
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

    private function occurredAt(?string $occurredAt, bool $clamp): CarbonImmutable
    {
        $now = now()->toImmutable();

        if ($occurredAt === null || trim($occurredAt) === '') {
            return $now;
        }

        $resolvedOccurredAt = CarbonImmutable::parse($occurredAt);

        if (! $clamp) {
            return $resolvedOccurredAt;
        }

        $configuredPastMinutes = config('capell-insights.ingest.max_past_minutes', 1440);
        $configuredFutureMinutes = config('capell-insights.ingest.max_future_minutes', 5);
        $pastMinutes = is_numeric($configuredPastMinutes) ? max(0, (int) $configuredPastMinutes) : 1440;
        $futureMinutes = is_numeric($configuredFutureMinutes) ? max(0, (int) $configuredFutureMinutes) : 5;
        $earliest = $now->subMinutes($pastMinutes);
        $latest = $now->addMinutes($futureMinutes);

        if ($resolvedOccurredAt->lessThan($earliest)) {
            return $earliest;
        }

        return $resolvedOccurredAt->greaterThan($latest) ? $latest : $resolvedOccurredAt;
    }

    private function hasTrustedSite(Request $request): bool
    {
        return $request->attributes->get('site') instanceof Site;
    }

    private function visitBelongsToTrustedSite(InsightsVisit $visit, Request $request): bool
    {
        $site = $request->attributes->get('site');

        if (! $site instanceof Site || ! is_numeric($site->getKey())) {
            return false;
        }

        return (int) $visit->site_id === (int) $site->getKey();
    }
}
