<?php

declare(strict_types=1);

namespace Capell\Insights\Listeners;

use Capell\Frontend\Contracts\FrontendContextReader;
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
        $this->prepare($event->context);
    }

    public function prepare(FrontendContextReader $context): void
    {
        // Request-scoped state, outside the shared public render-data cache.
        $context->setFrontendData(self::CONTEXT_KEY, InsightsConsentRegion::Unknown);

        if (! $this->tracker->shouldRenderForCurrentRequest()) {
            return;
        }

        $context->setFrontendData(self::CONTEXT_KEY, $this->resolver->resolve());
    }
}
