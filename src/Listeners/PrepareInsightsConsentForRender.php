<?php

declare(strict_types=1);

namespace Capell\Insights\Listeners;

use Capell\Frontend\Events\FrontendRenderPreparing;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Support\Consent\ConsentRegionResolver;
use Capell\Insights\Support\RenderHooks\RegisterInsightsTrackerHook;

final readonly class PrepareInsightsConsentForRender
{
    public const string CONTEXT_KEY = 'insights.consent.region';

    public function __construct(
        private ConsentRegionResolver $resolver,
        private RegisterInsightsTrackerHook $tracker,
    ) {}

    public function handle(FrontendRenderPreparing $event): void
    {
        // Request-scoped state, outside the shared public render-data cache.
        $event->context->setFrontendData(self::CONTEXT_KEY, InsightsConsentRegion::Unknown);

        if (! $this->tracker->shouldRenderForCurrentRequest()) {
            return;
        }

        $event->context->setFrontendData(self::CONTEXT_KEY, $this->resolver->resolve());
    }
}
