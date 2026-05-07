<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $eventsTableName = config('capell-insights.tables.events', 'insights_events');
        $visitsTableName = config('capell-insights.tables.visits', 'insights_visits');

        Schema::table($eventsTableName, function (Blueprint $table): void {
            if (! Schema::hasColumn(config('capell-insights.tables.events', 'insights_events'), 'legacy_page_view_id')) {
                $table->unsignedBigInteger('legacy_page_view_id')->nullable();
            }

            if (! Schema::hasIndex(config('capell-insights.tables.events', 'insights_events'), 'insights_events_legacy_page_view_index')) {
                $table->index('legacy_page_view_id', 'insights_events_legacy_page_view_index');
            }

            if (! Schema::hasIndex(config('capell-insights.tables.events', 'insights_events'), 'insights_events_type_occurred_index')) {
                $table->index(['type', 'occurred_at'], 'insights_events_type_occurred_index');
            }

            if (! Schema::hasIndex(config('capell-insights.tables.events', 'insights_events'), 'insights_events_site_type_occurred_index')) {
                $table->index(['site_id', 'type', 'occurred_at'], 'insights_events_site_type_occurred_index');
            }

            if (! Schema::hasIndex(config('capell-insights.tables.events', 'insights_events'), 'insights_events_visit_sequence_index')) {
                $table->index(['visit_id', 'sequence'], 'insights_events_visit_sequence_index');
            }
        });

        if (! Schema::hasIndex($eventsTableName, 'insights_events_path_type_occurred_index')) {
            DB::statement(sprintf(
                'ALTER TABLE %s ADD INDEX `insights_events_path_type_occurred_index` (`path`(191), `type`, `occurred_at`)',
                $this->quoteIdentifier($eventsTableName),
            ));
        }

        Schema::table($visitsTableName, function (Blueprint $table): void {
            if (! Schema::hasColumn(config('capell-insights.tables.visits', 'insights_visits'), 'legacy_session_id')) {
                $table->string('legacy_session_id', 64)->nullable();
            }

            if (! Schema::hasIndex(config('capell-insights.tables.visits', 'insights_visits'), 'insights_visits_legacy_session_index')) {
                $table->index('legacy_session_id', 'insights_visits_legacy_session_index');
            }
        });
    }

    public function down(): void
    {
        $eventsTableName = config('capell-insights.tables.events', 'insights_events');
        $visitsTableName = config('capell-insights.tables.visits', 'insights_visits');

        Schema::table($eventsTableName, function (Blueprint $table): void {
            $table->dropIndex('insights_events_legacy_page_view_index');
            $table->dropIndex('insights_events_type_occurred_index');
            $table->dropIndex('insights_events_site_type_occurred_index');
            $table->dropIndex('insights_events_path_type_occurred_index');
            $table->dropIndex('insights_events_visit_sequence_index');
            $table->dropColumn('legacy_page_view_id');
        });

        Schema::table($visitsTableName, function (Blueprint $table): void {
            $table->dropIndex('insights_visits_legacy_session_index');
            $table->dropColumn('legacy_session_id');
        });
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
};
