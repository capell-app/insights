<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsEventData;
use Capell\Insights\Data\InsightsEventMetadataData;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsEvent;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordConversionAction
{
    use AsAction;

    public function handle(
        ?string $visitUuid,
        string $eventName,
        string $url,
        ?string $label = null,
        ?string $sourcePackage = null,
        ?float $value = null,
        ?string $currency = null,
        ?string $occurredAt = null,
    ): ?InsightsEvent {
        $normalizedEventName = trim($eventName);
        $normalizedUrl = trim($url);

        if ($normalizedEventName === '' || $normalizedUrl === '') {
            return null;
        }

        return RecordCustomActionAction::run(
            $visitUuid,
            new InsightsEventData(
                type: InsightsEventType::Custom,
                url: $normalizedUrl,
                eventName: $normalizedEventName,
                label: $label,
                metadata: new InsightsEventMetadataData(
                    sourcePackage: $sourcePackage,
                    conversionValue: $value,
                    conversionCurrency: $currency,
                ),
            ),
            $occurredAt,
        );
    }
}
