<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable($this->tableName())) {
            return;
        }

        Schema::create($this->tableName(), function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('site_scope_id')->default(0);
            $table->string('timezone', 64);
            $table->unsignedBigInteger('unique_visitors')->default(0);
            $table->unsignedBigInteger('visits')->default(0);
            $table->unsignedBigInteger('page_views')->default(0);
            $table->timestamps();

            $table->unique(['day', 'site_scope_id'], TableName::namedIndex($this->baseTableName(), 'insights_daily_visitors_day_site_unique'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }

    private function baseTableName(): string
    {
        return Config::string('capell-insights.tables.daily_visitors', 'insights_daily_visitors');
    }

    private function tableName(): string
    {
        return TableName::resolve($this->baseTableName());
    }
};
