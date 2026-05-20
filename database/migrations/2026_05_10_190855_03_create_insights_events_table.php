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
        $tableName = config('capell-insights.tables.events', 'insights_events');
        $visitsTableName = config('capell-insights.tables.visits', 'insights_visits');

        Schema::create($tableName, function (Blueprint $table) use ($visitsTableName): void {
            $table->id();
            $table->foreignId('visit_id')->nullable()->constrained($visitsTableName)->nullOnDelete();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->unsignedBigInteger('language_id')->nullable()->index();
            $table->string('type')->index();
            $table->string('url', 512)->index();
            $table->string('path', 512)->index();
            $table->string('title')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->unsignedInteger('sequence');
            $table->string('event_name')->nullable()->index();
            $table->string('label')->nullable();
            $table->string('location')->nullable()->index();
            $table->string('target_selector')->nullable();
            $table->integer('viewport_x')->nullable();
            $table->integer('viewport_y')->nullable();
            $table->integer('document_x')->nullable();
            $table->integer('document_y')->nullable();
            $table->unsignedBigInteger('legacy_page_view_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('legacy_page_view_id', 'insights_events_legacy_page_view_index');
            $table->index(['type', 'occurred_at'], 'insights_events_type_occurred_index');
            $table->index(['site_id', 'type', 'occurred_at'], 'insights_events_site_type_occurred_index');
            $table->index(['visit_id', 'sequence'], 'insights_events_visit_sequence_index');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE %s ADD INDEX `insights_events_path_type_occurred_index` (`path`(191), `type`, `occurred_at`)',
                $this->quoteIdentifier($tableName),
            ));

            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->index(['path', 'type', 'occurred_at'], 'insights_events_path_type_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('capell-insights.tables.events', 'insights_events'));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
};
