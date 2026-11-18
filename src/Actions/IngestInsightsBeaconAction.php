<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsBeaconData;
use Capell\Insights\Data\InsightsEventData;
use Capell\Insights\Data\InsightsRequestContextData;
use Capell\Insights\Jobs\ProcessInsightsBeaconJob;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string|null run(InsightsBeaconData $data, Request $request)
 */
final class IngestInsightsBeaconAction
{
    use AsFake;
    use AsObject;

    public function handle(InsightsBeaconData $data, Request $request): ?string
    {
        $recordEvents = resolve(RecordInsightsEventsAction::class);

        if ($recordEvents->shouldIgnoreRequest($request) || ! $this->shouldSample($data, $request)) {
            return null;
        }

        $data = $data->withVisitUuid($this->callerBoundVisitUuid($data->visitUuid, $request));

        if ($this->canQueue($data)) {
            ProcessInsightsBeaconJob::dispatch(
                $data->forQueue(),
                InsightsRequestContextData::fromRequest($request),
            )->afterCommit();

            return null;
        }

        $recordedEvents = RecordInsightsEventsAction::run(
            visitUuid: $data->visitUuid,
            events: $data->events,
            request: $request,
            consentRegion: $data->visitUuid === null ? ResolveConsentRegionAction::run() : null,
            clampOccurredAt: true,
            beaconId: $data->beaconId,
            referrerUrl: $data->referrerUrl,
        );

        $recordedEvent = $recordedEvents->first();
        $visit = $recordedEvent instanceof InsightsEvent
            ? InsightsVisit::query()->find($recordedEvent->visit_id)
            : null;

        if (! $visit instanceof InsightsVisit || $visit->uuid === $data->visitUuid) {
            return null;
        }

        return $visit->uuid;
    }

    /**
     * A posted visit id is only honoured for the browser that owns it: the visit cookie when
     * present, otherwise the same hashed address and browser. Anything else starts a new visit.
     */
    private function callerBoundVisitUuid(?string $visitUuid, Request $request): ?string
    {
        if ($visitUuid === null || trim($visitUuid) === '') {
            return null;
        }

        $cookieVisitUuid = $request->cookie(RecordInsightsEventsAction::VISIT_COOKIE);

        if (is_string($cookieVisitUuid) && $cookieVisitUuid !== '') {
            return hash_equals($cookieVisitUuid, $visitUuid) ? $visitUuid : null;
        }

        $visit = InsightsVisit::query()
            ->where('uuid', $visitUuid)
            ->first(['id', 'site_id', 'ip_hash', 'user_agent_hash']);

        if (! $visit instanceof InsightsVisit) {
            return null;
        }

        $siteId = $visit->site_id === null ? null : (int) $visit->site_id;
        $ipHash = HashInsightsVisitorValueAction::run($request->ip(), $siteId);
        $userAgentHash = HashInsightsVisitorValueAction::run($request->userAgent(), $siteId);

        if (! is_string($visit->ip_hash) || ! is_string($visit->user_agent_hash) || $ipHash === null || $userAgentHash === null) {
            return null;
        }

        return hash_equals($visit->ip_hash, $ipHash) && hash_equals($visit->user_agent_hash, $userAgentHash) ? $visitUuid : null;
    }

    private function canQueue(InsightsBeaconData $data): bool
    {
        if ($data->visitUuid === null || config('capell-insights.ingest.queue_enabled', true) !== true) {
            return false;
        }

        $visit = InsightsVisit::query()
            ->where('uuid', $data->visitUuid)
            ->first(['id', 'last_seen_at']);

        if (! $visit instanceof InsightsVisit || ! $visit->last_seen_at instanceof CarbonImmutable) {
            return true;
        }

        $configuredTimeoutMinutes = config('capell-insights.session_timeout_minutes', 30);
        $timeoutMinutes = is_numeric($configuredTimeoutMinutes) ? (int) $configuredTimeoutMinutes : 30;

        return $timeoutMinutes < 1 || $visit->last_seen_at->addMinutes($timeoutMinutes)->isFuture();
    }

    private function shouldSample(InsightsBeaconData $data, Request $request): bool
    {
        $sampleRate = ResolveInsightsSampleRateAction::run();

        if ($sampleRate <= 0.0) {
            return false;
        }

        if ($sampleRate >= 1.0) {
            return true;
        }

        $firstEvent = $data->events[0]['data'] ?? null;
        $identity = $data->visitUuid ?? implode('|', [
            $request->ip() ?? '',
            $request->userAgent() ?? '',
            $firstEvent instanceof InsightsEventData ? $firstEvent->url : '',
        ]);
        $bucket = hexdec(substr(hash('sha256', $identity), 0, 8)) / 0xFFFFFFFF;

        return $bucket < $sampleRate;
    }
}
