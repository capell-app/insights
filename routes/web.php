<?php

declare(strict_types=1);

use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Insights\Http\Controllers\InsightsBeaconController;
use Capell\Insights\Http\Controllers\InsightsConsentController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

$routePrefix = trim(config('capell-insights.route_prefix', 'capell/insights'), '/');

Route::prefix($routePrefix)
    ->middleware(['web'])
    ->group(function (): void {
        Route::post('events', InsightsBeaconController::class)
            ->middleware(['throttle:60,1'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('capell-insights.events');

        Route::post('consent', InsightsConsentController::class)
            ->middleware(['throttle:60,1'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('capell-insights.consent');
    });

if (config('capell-insights.screenshot_fixtures_enabled', false) === true) {
    Route::get('/screenshot-fixtures/insights/{screen}', static function (string $screen): Response {
        abort_unless(in_array($screen, ['frontend-page-with-tracker-active', 'consent-banner-flow'], true), 404);

        Config::set(
            'capell-insights.consent_banner_enabled',
            $screen === 'consent-banner-flow',
        );

        /** @var RenderHookRegistry<RenderHookContext> $registry */
        $registry = resolve(RenderHookRegistry::class);
        $bodyEnd = $registry->renderAll(RenderHookLocation::BodyEnd);

        return response()->make(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Insights public analytics fixture</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #111827;
            background: #f8fafc;
        }

        body {
            margin: 0;
            background:
                radial-gradient(circle at 18% 20%, rgb(20 184 166 / 16%), transparent 28rem),
                linear-gradient(135deg, #f8fafc 0%, #eef2ff 48%, #f0fdfa 100%);
        }

        .insights-fixture {
            display: grid;
            min-height: 100vh;
            place-items: center;
            padding: 3rem 1rem;
        }

        .insights-fixture__panel {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(20rem, 0.9fr);
            gap: 2rem;
            width: min(70rem, 100%);
            border: 1px solid rgb(15 23 42 / 10%);
            border-radius: 0.75rem;
            background: rgb(255 255 255 / 88%);
            box-shadow: 0 1.5rem 4rem rgb(15 23 42 / 14%);
            padding: clamp(1.25rem, 4vw, 3rem);
        }

        .insights-fixture__eyebrow {
            margin: 0 0 0.75rem;
            color: #0f766e;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .insights-fixture h1 {
            margin: 0;
            max-width: 13ch;
            color: #0f172a;
            font-size: clamp(2.75rem, 7vw, 5.25rem);
            letter-spacing: 0;
            line-height: 0.95;
        }

        .insights-fixture__copy {
            max-width: 42rem;
            margin: 1.25rem 0 0;
            color: #475569;
            font-size: 1.05rem;
            line-height: 1.7;
        }

        .insights-fixture__metrics {
            display: grid;
            gap: 0.75rem;
            align-content: center;
        }

        .insights-fixture__metric {
            border: 1px solid rgb(15 23 42 / 8%);
            border-radius: 0.5rem;
            background: #ffffff;
            padding: 1rem;
        }

        .insights-fixture__metric strong {
            display: block;
            color: #0f172a;
            font-size: 1.75rem;
            line-height: 1;
        }

        .insights-fixture__metric span {
            display: block;
            margin-top: 0.35rem;
            color: #64748b;
            font-size: 0.9rem;
        }

        @media (max-width: 760px) {
            .insights-fixture__panel {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="insights-fixture" data-insights-screenshot-fixture>
        <section class="insights-fixture__panel" aria-label="Insights public analytics fixture">
            <div>
                <p class="insights-fixture__eyebrow">First-party analytics</p>
                <h1>Privacy-aware insight, built in.</h1>
                <p class="insights-fixture__copy">
                    This route-backed fixture renders a real public Capell page with the Insights BodyEnd hook active, so screenshots prove the tracker and consent banner in a styled frontend context.
                </p>
            </div>
            <div class="insights-fixture__metrics" aria-label="Sample analytics outcomes">
                <div class="insights-fixture__metric">
                    <strong>12.4k</strong>
                    <span>monthly page views measured server-side</span>
                </div>
                <div class="insights-fixture__metric">
                    <strong>86%</strong>
                    <span>known consent decisions mirrored locally</span>
                </div>
                <div class="insights-fixture__metric">
                    <strong>0</strong>
                    <span>third-party analytics scripts required</span>
                </div>
            </div>
        </section>
    </main>
    {$bodyEnd}
</body>
</html>
HTML);
    });
}
