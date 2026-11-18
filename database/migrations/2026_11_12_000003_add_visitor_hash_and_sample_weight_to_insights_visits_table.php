<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string VISITOR_INDEX = 'insights_visits_visitor_hash_index';

    public function up(): void
    {
        $tableName = $this->tableName();

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'visitor_hash')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->char('visitor_hash', 64)->nullable();
            $table->decimal('sample_weight', 12, 4)->default(1);
            $table->index('visitor_hash', TableName::namedIndex($this->baseTableName(), self::VISITOR_INDEX));
        });
    }

    public function down(): void
    {
        $tableName = $this->tableName();

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'visitor_hash')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropIndex(TableName::namedIndex($this->baseTableName(), self::VISITOR_INDEX));
            $table->dropColumn(['visitor_hash', 'sample_weight']);
        });
    }

    private function baseTableName(): string
    {
        return Config::string('capell-insights.tables.visits', 'insights_visits');
    }

    private function tableName(): string
    {
        return TableName::resolve($this->baseTableName());
    }
};
