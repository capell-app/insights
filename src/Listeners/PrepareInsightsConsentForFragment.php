<?php

declare(strict_types=1);

namespace Capell\Insights\Listeners;

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Events\RenderHookFragmentPreparing;
use Capell\Insights\Support\RenderHooks\RegisterInsightsTrackerHook;

final readonly class PrepareInsightsConsentForFragment
{
    public function __construct(
        private PrepareInsightsConsentForRender $preparer,
    ) {}

    public function handle(RenderHookFragmentPreparing $event): void
    {
        if ($event->fragment->stableKey !== RegisterInsightsTrackerHook::fragmentStableKey()) {
            return;
        }

        $this->preparer->prepare(resolve(FrontendContextReader::class));
    }
}
