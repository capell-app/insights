<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsBeaconData;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static string|null run(InsightsBeaconData $data, Request $request)
 */
final class IngestInsightsBeaconAction
{
    use AsAction;

    public function handle(InsightsBeaconData $data, Request $request): ?string
    {
        $recordedEvents = RecordInsightsEventsAction::run(
            visitUuid: $data->visitUuid,
            events: $data->events,
            request: $request,
            consentRegion: $data->visitUuid === null ? ResolveConsentRegionAction::run() : null,
        );

        if ($data->visitUuid !== null) {
            return null;
        }

        $recordedEvent = $recordedEvents->first();
        $visit = $recordedEvent instanceof InsightsEvent
            ? InsightsVisit::query()->find($recordedEvent->visit_id)
            : null;

        return $visit instanceof InsightsVisit ? $visit->uuid : null;
    }
}
