<?php

declare(strict_types=1);

use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = $this->tableName('capell-insights.tables.events', 'insights_events');
        $visitsTableName = $this->tableName('capell-insights.tables.visits', 'insights_visits');

        Schema::create($tableName, function (Blueprint $table) use ($visitsTableName): void {
            $table->id();
            $table->foreignId('visit_id')->nullable()->constrained($visitsTableName)->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained(TableName::resolve('sites'))->nullOnDelete();
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

            $table->index('legacy_page_view_id', TableName::namedIndex($this->baseTableName('capell-insights.tables.events', 'insights_events'), 'insights_events_legacy_page_view_index'));
            $table->index(['type', 'occurred_at'], TableName::namedIndex($this->baseTableName('capell-insights.tables.events', 'insights_events'), 'insights_events_type_occurred_index'));
            $table->index(['site_id', 'type', 'occurred_at'], TableName::namedIndex($this->baseTableName('capell-insights.tables.events', 'insights_events'), 'insights_events_site_type_occurred_index'));
            $table->index(['visit_id', 'sequence'], TableName::namedIndex($this->baseTableName('capell-insights.tables.events', 'insights_events'), 'insights_events_visit_sequence_index'));
        });

        $connection = Schema::getConnection();
        $index = CapellDatabase::for($connection)->schemaDialect()->prefixedIndex(
            new DatabaseIndexDefinition(
                table: $tableName,
                name: 'insights_events_path_type_occurred_index',
                columns: ['path', 'type', 'occurred_at'],
                prefixLengths: ['path' => 191],
            ),
        );

        DB::statement($index->sql, $index->bindings);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName('capell-insights.tables.events', 'insights_events'));
    }

    private function baseTableName(string $key, string $fallback): string
    {
        $name = config($key);

        return is_string($name) && $name !== '' ? $name : $fallback;
    }

    private function tableName(string $key, string $fallback): string
    {
        $tableName = config($key, $fallback);

        return TableName::resolve(is_string($tableName) && $tableName !== '' ? $tableName : $fallback);
    }
};
