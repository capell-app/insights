<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Capell\Insights\Support\InsightsUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Earlier visits stored full landing and referrer URLs, query strings included, so tokens
 * and personal data in links were kept. This keeps origin and path only, and recovers
 * campaign tags from landing URLs that were stored before they were read correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = TableName::resolve(Config::string('capell-insights.tables.visits', 'insights_visits'));

        if (! Schema::hasTable($tableName)) {
            return;
        }

        DB::table($tableName)
            ->select(['id', 'landing_url', 'referrer_url', 'utm_source', 'utm_medium', 'utm_campaign'])
            ->where(function ($query): void {
                $query->where('landing_url', 'like', '%?%')
                    ->orWhere('landing_url', 'like', '%#%')
                    ->orWhere('referrer_url', 'like', '%?%')
                    ->orWhere('referrer_url', 'like', '%#%');
            })
            ->orderBy('id')
            ->chunkById(500, function (Collection $visits) use ($tableName): void {
                foreach ($visits as $visit) {
                    $landingUrl = is_string($visit->landing_url) ? $visit->landing_url : null;
                    $referrerUrl = is_string($visit->referrer_url) ? $visit->referrer_url : null;
                    $utm = InsightsUrl::utmParameters($landingUrl);

                    DB::table($tableName)->where('id', (int) $visit->id)->update([
                        'landing_url' => InsightsUrl::originAndPath($landingUrl) ?? '',
                        'referrer_url' => InsightsUrl::originAndPath($referrerUrl),
                        'utm_source' => $visit->utm_source ?? $utm['utm_source'],
                        'utm_medium' => $visit->utm_medium ?? $utm['utm_medium'],
                        'utm_campaign' => $visit->utm_campaign ?? $utm['utm_campaign'],
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Forward-only: the removed query strings are not recoverable.
    }
};
