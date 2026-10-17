<?php

declare(strict_types=1);

use Capell\Tests\Support\JavaScriptScenario;

it('tracks consented visits and labelled clicks while respecting privacy signals and ignored targets', function (): void {
    JavaScriptScenario::run(dirname(__DIR__, 2) . '/JavaScript/tracker-runtime.test.mjs');
});
