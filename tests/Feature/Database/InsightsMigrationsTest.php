<?php

declare(strict_types=1);

use Capell\Insights\Actions\ImportLegacyPageViewsAction;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Capell\Insights\Providers\InsightsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelPackageTools\Package;

it('loads insights migrations', function (): void {
    expect(Schema::hasTable('insights_visits'))->toBeTrue()
        ->and(Schema::hasTable('insights_consents'))->toBeTrue()
        ->and(Schema::hasTable('insights_events'))->toBeTrue()
        ->and(Schema::hasTable('insights_daily_rollups'))->toBeTrue()
        ->and(Schema::hasColumn('insights_visits', 'uuid'))->toBeTrue()
        ->and(Schema::hasColumn('insights_visits', 'legacy_session_id'))->toBeTrue()
        ->and(Schema::hasColumn('insights_consents', 'categories'))->toBeTrue()
        ->and(Schema::hasColumn('insights_events', 'document_y'))->toBeTrue()
        ->and(Schema::hasColumn('insights_events', 'legacy_page_view_id'))->toBeTrue()
        ->and(Schema::hasColumn('insights_daily_rollups', 'unique_visits'))->toBeTrue()
        ->and(Schema::hasColumn('insights_daily_rollups', 'path_digest'))->toBeTrue()
        ->and(Schema::hasColumn('page_urls', 'hit_count'))->toBeTrue()
        ->and(Schema::hasColumn('page_urls', 'last_hit_at'))->toBeTrue()
        ->and(Schema::hasIndex('insights_events', 'insights_events_path_type_occurred_index'))->toBeTrue();
});

it('uniquely identifies complete rollup paths by sha-256 digest', function (): void {
    $sharedPrefix = '/' . str_repeat('a', 191);
    $attributes = [
        'day' => '2026-07-21',
        'site_scope_id' => 1,
        'language_scope_id' => 1,
        'type' => InsightsEventType::PageView->value,
        'events' => 1,
    ];

    $first = InsightsDailyRollup::query()->create([
        ...$attributes,
        'path' => $sharedPrefix . '-first',
    ]);
    $second = InsightsDailyRollup::query()->create([
        ...$attributes,
        'path' => $sharedPrefix . '-second',
    ]);

    expect($first->path_digest)->toBe(hash('sha256', $first->path))
        ->and($second->path_digest)->toBe(hash('sha256', $second->path))
        ->and($first->path_digest)->not->toBe($second->path_digest);

    InsightsDailyRollup::query()->create([
        ...$attributes,
        'path' => $first->path,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('upgrades existing daily rollups to digest uniqueness without losing data', function (): void {
    Schema::drop('insights_daily_rollups');

    $initialMigration = require dirname(__DIR__, 3) . '/database/migrations/2026_06_06_000001_create_insights_daily_rollups_table.php';
    $initialMigration->up();

    DB::table('insights_daily_rollups')->insert([
        'day' => '2026-07-21',
        'site_id' => null,
        'language_id' => null,
        'site_scope_id' => 0,
        'language_scope_id' => 0,
        'type' => InsightsEventType::PageView->value,
        'path' => '/existing-page',
        'url' => 'https://example.test/existing-page',
        'events' => 3,
        'page_views' => 3,
        'clicks' => 0,
        'unique_visits' => 2,
        'created_at' => '2026-07-21 12:00:00',
        'updated_at' => '2026-07-21 12:00:00',
    ]);

    $addDigestMigration = require dirname(__DIR__, 3) . '/database/migrations/2026_07_22_000001_add_path_digest_to_insights_daily_rollups_table.php';
    $addDigestMigration->up();
    $addDigestMigration->up();

    $rollup = DB::table('insights_daily_rollups')->sole();

    expect(Schema::hasColumn('insights_daily_rollups', 'path_digest'))->toBeTrue()
        ->and($rollup->path)->toBe('/existing-page')
        ->and($rollup->events)->toBe(3)
        ->and($rollup->path_digest)->toBe(hash('sha256', '/existing-page'));

    DB::table('insights_daily_rollups')->insert([
        'day' => '2026-07-21',
        'site_id' => null,
        'language_id' => null,
        'site_scope_id' => 0,
        'language_scope_id' => 0,
        'type' => InsightsEventType::PageView->value,
        'path' => '/existing-page',
        'path_digest' => hash('sha256', '/existing-page'),
        'url' => 'https://example.test/existing-page',
        'events' => 3,
        'page_views' => 3,
        'clicks' => 0,
        'unique_visits' => 2,
        'created_at' => '2026-07-21 12:00:00',
        'updated_at' => '2026-07-21 12:00:00',
    ]);
})->throws(UniqueConstraintViolationException::class);

it('upgrades a configured daily rollups table', function (): void {
    config()->set('capell-insights.tables.daily_rollups', 'custom_insights_daily_rollups');
    Schema::drop('insights_daily_rollups');

    $initialMigration = require dirname(__DIR__, 3) . '/database/migrations/2026_06_06_000001_create_insights_daily_rollups_table.php';
    $initialMigration->up();

    $addDigestMigration = require dirname(__DIR__, 3) . '/database/migrations/2026_07_22_000001_add_path_digest_to_insights_daily_rollups_table.php';
    $addDigestMigration->up();
    $addDigestMigration->up();

    expect(Schema::hasTable('custom_insights_daily_rollups'))->toBeTrue()
        ->and(Schema::hasColumn('custom_insights_daily_rollups', 'path_digest'))->toBeTrue()
        ->and(Schema::hasIndex(
            'custom_insights_daily_rollups',
            ['day', 'site_scope_id', 'language_scope_id', 'type', 'path_digest'],
            'unique',
        ))->toBeTrue();
});

it('preserves digest schema when rolling back the corrective migration', function (): void {
    $addDigestMigration = require dirname(__DIR__, 3) . '/database/migrations/2026_07_22_000001_add_path_digest_to_insights_daily_rollups_table.php';

    $addDigestMigration->up();
    $addDigestMigration->down();

    expect(Schema::hasColumn('insights_daily_rollups', 'path_digest'))->toBeTrue()
        ->and(Schema::hasIndex(
            'insights_daily_rollups',
            ['day', 'site_scope_id', 'language_scope_id', 'type', 'path_digest'],
            'unique',
        ))->toBeTrue();
});

it('registers the canonical migration filenames', function (): void {
    $package = new Package;

    (new InsightsServiceProvider(app()))->configurePackage($package);

    $migrationsDirectory = dirname(__DIR__, 3) . '/database/migrations';

    expect($package->migrationFileNames)->toBe([
        '2026_05_10_190855_01_create_insights_visits_table',
        '2026_05_10_190855_02_create_insights_consents_table',
        '2026_05_10_190855_03_create_insights_events_table',
        '2026_05_10_190855_05_import_legacy_page_views',
        '2026_06_06_000001_create_insights_daily_rollups_table',
        '2026_07_22_000001_add_path_digest_to_insights_daily_rollups_table',
    ]);

    foreach ($package->migrationFileNames as $migrationFileName) {
        expect(file_exists($migrationsDirectory . '/' . $migrationFileName . '.php'))
            ->toBeTrue(sprintf("Registered migration '%s' has no file on disk.", $migrationFileName));
    }
});

it('imports legacy page views idempotently into insights events', function (): void {
    Schema::create('page_views', function (Blueprint $table): void {
        $table->id();
        $table->string('url');
        $table->string('session_id', 64);
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->string('pageable_type')->nullable();
        $table->unsignedBigInteger('pageable_id')->nullable();
        $table->unsignedInteger('visits')->default(1);
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('viewed_at')->nullable();
    });

    DB::table('page_views')->insert([
        'id' => 1001,
        'url' => 'https://example.test/imported',
        'session_id' => str_repeat('a', 64),
        'site_id' => null,
        'language_id' => null,
        'pageable_type' => null,
        'pageable_id' => null,
        'visits' => 2,
        'user_id' => null,
        'created_at' => '2026-04-20 09:00:00',
        'viewed_at' => '2026-04-20 09:05:00',
    ]);

    expect(ImportLegacyPageViewsAction::run())->toBe(2)
        ->and(ImportLegacyPageViewsAction::run())->toBe(0)
        ->and(InsightsVisit::query()->where('legacy_session_id', str_repeat('a', 64))->count())->toBe(1)
        ->and(InsightsEvent::query()->where('legacy_page_view_id', 1001)->count())->toBe(2);

    $event = InsightsEvent::query()->where('legacy_page_view_id', 1001)->firstOrFail();

    expect($event->type)->toBe(InsightsEventType::PageView)
        ->and($event->path)->toBe('/imported');
});
