<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('language_id')->nullable();
            $table->unsignedBigInteger('site_scope_id')->default(0);
            $table->unsignedBigInteger('language_scope_id')->default(0);
            $table->string('type', 32);
            $table->string('path', 2048);
            $table->string('url', 2048)->nullable();
            $table->unsignedBigInteger('events')->default(0);
            $table->unsignedBigInteger('page_views')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('unique_visits')->default(0);
            $table->timestamps();

            $table->index(['day', 'type'], 'insights_rollups_day_type_index');
            $table->index(['site_id', 'language_id', 'day'], 'insights_rollups_site_language_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }

    private function tableName(): string
    {
        $tableName = config('capell-insights.tables.daily_rollups', 'insights_daily_rollups');

        return is_string($tableName) && $tableName !== '' ? $tableName : 'insights_daily_rollups';
    }
};
