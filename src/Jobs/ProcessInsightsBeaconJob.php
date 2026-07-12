<?php

declare(strict_types=1);

namespace Capell\Insights\Jobs;

use Capell\Insights\Actions\RecordInsightsEventsAction;
use Capell\Insights\Data\InsightsBeaconData;
use Capell\Insights\Data\InsightsRequestContextData;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessInsightsBeaconJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly InsightsBeaconData $data,
        public readonly InsightsRequestContextData $requestContext,
    ) {
        $connection = config('capell-insights.ingest.queue_connection');
        $queue = config('capell-insights.ingest.queue_name', 'default');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : 'default');
    }

    public function handle(): void
    {
        RecordInsightsEventsAction::run(
            visitUuid: $this->data->visitUuid,
            events: $this->data->events,
            request: $this->requestContext->toRequest(),
            clampOccurredAt: true,
            startNewSession: false,
        );
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [1, 5, 15];
    }
}
