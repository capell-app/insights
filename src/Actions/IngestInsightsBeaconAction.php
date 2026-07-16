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
        $configuredRate = config('capell-insights.ingest.sample_rate', 1.0);
        $sampleRate = is_numeric($configuredRate) ? max(0.0, min(1.0, (float) $configuredRate)) : 1.0;

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
