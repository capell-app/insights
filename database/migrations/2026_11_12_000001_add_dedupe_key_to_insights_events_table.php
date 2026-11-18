<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string UNIQUE_INDEX = 'insights_events_dedupe_key_unique';

    public function up(): void
    {
        $tableName = $this->tableName();

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'dedupe_key')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->char('dedupe_key', 64)->nullable();
            $table->unique('dedupe_key', TableName::namedIndex($this->baseTableName(), self::UNIQUE_INDEX));
        });
    }

    public function down(): void
    {
        $tableName = $this->tableName();

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'dedupe_key')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropUnique(TableName::namedIndex($this->baseTableName(), self::UNIQUE_INDEX));
            $table->dropColumn('dedupe_key');
        });
    }

    private function baseTableName(): string
    {
        return Config::string('capell-insights.tables.events', 'insights_events');
    }

    private function tableName(): string
    {
        return TableName::resolve($this->baseTableName());
    }
};
