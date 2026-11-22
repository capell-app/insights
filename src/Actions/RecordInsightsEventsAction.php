<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Enums\ConsentDecision;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Http\CrawlerDetector;
use Capell\Insights\Data\InsightsEventData;
use Capell\Insights\Enums\InsightsConsentRegion;
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
 * @method static Collection<int, InsightsEvent> run(?string $visitUuid, iterable<int, array{data: InsightsEventData, occurred_at: string|null}> $events, ?Request $request = null, ?InsightsConsentRegion $consentRegion = null, bool $clampOccurredAt = false, bool $startNewSession = true, ?string $beaconId = null, ?string $referrerUrl = null)
 */
final class RecordInsightsEventsAction
{
    use AsFake;
    use AsObject;

    public const string VISIT_COOKIE = 'capell_insights_visit';

    /**
     * @param  iterable<int, array{data: InsightsEventData, occurred_at: string|null}>  $events
     * @return Collection<int, InsightsEvent>
     */
    public function handle(?string $visitUuid, iterable $events, ?Request $request = null, ?InsightsConsentRegion $consentRegion = null, bool $clampOccurredAt = false, bool $startNewSession = true, ?string $beaconId = null, ?string $referrerUrl = null): Collection
    {
        if (! ResolveInsightsRuntimeSettingsAction::run()->enabled) {
            return collect();
        }

        if ($request instanceof Request && $this->shouldIgnoreRequest($request)) {
            return collect();
        }

        if ($request instanceof Request && ResolveInsightsAnalyticsDecisionAction::run($request) === ConsentDecision::Denied) {
            return collect();
        }

        return DB::transaction(function () use ($visitUuid, $events, $request, $consentRegion, $clampOccurredAt, $startNewSession, $beaconId, $referrerUrl): Collection {
            $recordableEvents = $this->recordableEvents($events);

            if ($recordableEvents === []) {
                return collect();
            }

            $visit = $this->resolveVisit($visitUuid, $request, $consentRegion, $startNewSession, $recordableEvents[0]['data']->url, $referrerUrl);

            if (! $visit instanceof InsightsVisit || ! CanRecordForInsightsVisitAction::run($visit)) {
                return collect();
            }

            $now = now()->toImmutable();
            $maxSequence = $visit->events()->max('sequence');
            $sequence = is_numeric($maxSequence) ? ((int) $maxSequence) + 1 : 1;
            $eventRows = [];

            foreach ($recordableEvents as $index => $event) {
                $eventData = $event['data'];

                $eventRows[] = [
                    'dedupe_key' => $beaconId === null ? null : hash('sha256', sprintf('%s|%s|%d', $visit->uuid, $beaconId, $index)),
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

            $table = DB::table((new InsightsEvent)->getTable());

            if ($beaconId === null) {
                $table->insert($eventRows);
            } elseif ($table->insertOrIgnore($eventRows) === 0) {
                // The same beacon was already recorded (a resend or a retried job).
                return collect();
            }

            $visit->forceFill([
                'last_seen_at' => $now,
            ])->save();

            if ($beaconId !== null) {
                return $visit->events()
                    ->whereIn('dedupe_key', array_column($eventRows, 'dedupe_key'))
                    ->orderBy('sequence')
                    ->get()
                    ->values();
            }

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

        if ($this->isAutomatedRequest($request)) {
            return true;
        }

        return $this->isIgnoredUserAgent($request->userAgent());
    }

    /**
     * Known crawlers, preview fetchers and speculative (prefetch/prerender) loads are not visitors.
     */
    private function isAutomatedRequest(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Purpose'] as $header) {
            $value = strtolower((string) $request->headers->get($header));

            if (str_contains($value, 'prefetch') || str_contains($value, 'prerender')) {
                return true;
            }
        }

        $userAgent = $request->userAgent();

        return $userAgent !== null && trim($userAgent) !== '' && resolve(CrawlerDetector::class)->isCrawler($userAgent);
    }

    private function resolveVisit(?string $visitUuid, ?Request $request, ?InsightsConsentRegion $consentRegion, bool $startNewSession, ?string $landingUrl = null, ?string $referrerUrl = null): ?InsightsVisit
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
                return $this->startNextSessionVisit($visit, $request, $landingUrl, $referrerUrl);
            }

            return $visit;
        }

        if (! $request instanceof Request || ! $this->hasTrustedSite($request) || ! $consentRegion instanceof InsightsConsentRegion || ! CanRecordForInsightsVisitAction::make()->canRecordForRegion($consentRegion)) {
            return null;
        }

        $visit = CreateInsightsVisitAction::run($request, $consentRegion, $landingUrl, $referrerUrl);

        Cookie::queue(self::VISIT_COOKIE, $visit->uuid, 60 * 24 * 365);

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

    private function startNextSessionVisit(InsightsVisit $previousVisit, Request $request, ?string $landingUrl, ?string $referrerUrl): InsightsVisit
    {
        $visit = CreateInsightsVisitAction::run($request, $previousVisit->consent_region, $landingUrl, $referrerUrl);
        $consent = CanRecordForInsightsVisitAction::make()->latestConsent($previousVisit);

        if ($consent instanceof InsightsConsent) {
            // A new session preserves the original decision, including its expiry and policy.
            $visit->consents()->save($consent->replicate());
        }

        $visit->forceFill([
            'consent_status' => $consent->status ?? $previousVisit->consent_status,
        ])->save();

        Cookie::queue(self::VISIT_COOKIE, $visit->uuid, 60 * 24 * 365);

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

        return array_any(ResolveInsightsRuntimeSettingsAction::run()->ignoredPaths, static fn (mixed $ignoredPath): bool => is_string($ignoredPath) && Str::is($ignoredPath, $path));
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

        return array_any($ignoredIps, static fn (mixed $ignoredIp): bool => is_string($ignoredIp) && Str::is($ignoredIp, $ipAddress));
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

        return array_any($ignoredUserAgents, static fn (mixed $ignoredUserAgent): bool => is_string($ignoredUserAgent) && Str::is(strtolower($ignoredUserAgent), strtolower($userAgent)));
    }

    private function isAssetPath(string $path): bool
    {
        return preg_match('/\.(?:css|js|map|json|xml|txt|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|pdf|zip)$/i', $path) === 1;
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
