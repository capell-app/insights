<?php

declare(strict_types=1);

use Capell\Analytics\Tests\AnalyticsTestCase;

pest()->extend(AnalyticsTestCase::class)->group('analytics')->in(__DIR__);
