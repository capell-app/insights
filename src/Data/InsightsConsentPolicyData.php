<?php

declare(strict_types=1);

namespace Capell\Insights\Data;

use Capell\Core\Enums\ConsentDecision;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;

final class InsightsConsentPolicyData extends Data
{
    public function __construct(
        #[MapOutputName('consent_required')]
        public bool $consentRequired,
        #[MapOutputName('analytics_decision')]
        public string $analyticsDecision = ConsentDecision::Undecided->value,
    ) {}
}
