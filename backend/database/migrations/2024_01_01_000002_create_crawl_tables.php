<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type');                       // directory|openapi|sitemap|manual
            $table->string('url');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('rate_limit_per_minute')->default(30);
            $table->boolean('respect_robots_txt')->default(true);
            $table->json('config')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crawl_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_source_id')->nullable()->constrained('crawl_sources')->nullOnDelete();
            $table->string('status')->default('pending'); // pending|running|success|failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('api_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_id')->constrained('apis')->cascadeOnDelete();
            $table->string('status');                     // healthy|degraded|unhealthy|unknown
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->boolean('dns_ok')->default(false);
            $table->boolean('tls_ok')->default(false);
            $table->timestamp('tls_expires_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['api_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_health_checks');
        Schema::dropIfExists('crawl_jobs');
        Schema::dropIfExists('crawl_sources');
    }
};
