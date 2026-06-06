<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

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
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static Collection<int, InsightsEvent> run(?string $visitUuid, iterable<int, array{data: InsightsEventData, occurred_at: string|null}> $events, ?Request $request = null, ?InsightsConsentRegion $consentRegion = null)
 */
final class RecordInsightsEventsAction
{
    use AsAction;

    /**
     * @param  iterable<int, array{data: InsightsEventData, occurred_at: string|null}>  $events
     * @return Collection<int, InsightsEvent>
     */
    public function handle(?string $visitUuid, iterable $events, ?Request $request = null, ?InsightsConsentRegion $consentRegion = null): Collection
    {
        if (config('capell-insights.enabled', true) !== true) {
            return collect();
        }

        if ($request instanceof Request && $this->isIgnoredRequest($request)) {
            return collect();
        }

        $recordedEvents = DB::transaction(function () use ($visitUuid, $events, $request, $consentRegion): Collection {
            $recordableEvents = $this->recordableEvents($events);

            if ($recordableEvents === []) {
                return collect();
            }

            $visit = $this->resolveVisit($visitUuid, $request, $consentRegion);

            if (! $visit instanceof InsightsVisit || ! $this->canRecordForVisit($visit)) {
                return collect();
            }

            $now = now()->toImmutable();
            $sequence = ((int) $visit->events()->max('sequence')) + 1;
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
                    'occurred_at' => $this->occurredAt($event['occurred_at']),
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

        if ($recordedEvents->isNotEmpty()) {
            RememberInsightsDashboardAggregateAction::flush();
        }

        return $recordedEvents;
    }

    private function resolveVisit(?string $visitUuid, ?Request $request, ?InsightsConsentRegion $consentRegion): ?InsightsVisit
    {
        if ($visitUuid !== null && trim($visitUuid) !== '') {
            return InsightsVisit::query()
                ->where('uuid', $visitUuid)
                ->lockForUpdate()
                ->first();
        }

        if (! $request instanceof Request || ! $consentRegion instanceof InsightsConsentRegion || ! $this->canRecordForRegion($consentRegion)) {
            return null;
        }

        $visit = CreateInsightsVisitAction::run($request, $consentRegion);

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

    private function isIgnoredRequest(Request $request): bool
    {
        if ($this->isIgnoredIp($request->ip())) {
            return true;
        }

        return $this->isIgnoredUserAgent($request->userAgent());
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
            return $latestConsent->categories->insights;
        }

        return $visit->consent_status === InsightsConsentStatus::AcceptedAll;
    }

    private function occurredAt(?string $occurredAt): CarbonImmutable
    {
        if ($occurredAt === null || trim($occurredAt) === '') {
            return now()->toImmutable();
        }

        return CarbonImmutable::parse($occurredAt);
    }
}
