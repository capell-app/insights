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
        $tableName = TableName::resolve(Config::string('capell-insights.tables.consents', 'insights_consents'));
        $visitsTableName = TableName::resolve(Config::string('capell-insights.tables.visits', 'insights_visits'));

        Schema::create($tableName, function (Blueprint $table) use ($visitsTableName): void {
            $table->id();
            $table->foreignId('visit_id')->nullable()->constrained($visitsTableName)->nullOnDelete();
            $table->string('consent_region');
            $table->string('status');
            $table->json('categories');
            $table->string('policy_version');
            $table->timestamp('terms_accepted_at')->nullable();
            $table->dateTime('decided_at')->index();
            $table->string('ip_hash')->nullable();
            $table->string('user_agent_hash')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve(Config::string('capell-insights.tables.consents', 'insights_consents')));
    }
};
