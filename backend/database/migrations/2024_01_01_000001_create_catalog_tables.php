<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('apis', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('website')->nullable();
            $table->string('documentation_url')->nullable();
            $table->string('base_url')->nullable();
            $table->string('authentication_type')->default('unknown'); // none|apiKey|OAuth|bearer|unknown
            $table->boolean('https')->default(true);
            $table->string('cors')->default('unknown');               // yes|no|unknown
            $table->string('status')->default('active');              // active|deprecated|dead|unknown
            $table->string('version')->nullable();
            $table->string('license')->nullable();
            $table->string('country')->nullable();
            $table->string('source')->nullable();                     // seed|public-apis|apis-guru|manual
            $table->string('source_url')->nullable();
            $table->json('tags')->nullable();
            $table->string('openapi_url')->nullable();
            $table->boolean('has_openapi')->default(false);
            $table->unsignedTinyInteger('quality_score')->default(0); // 0..100, recomputed by scorer
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->index('category_id');
            $table->index('provider_id');
            $table->index('status');
            $table->index('authentication_type');
        });

        Schema::create('api_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_id')->constrained('apis')->cascadeOnDelete();
            $table->string('method', 10)->default('GET');
            $table->string('path');
            $table->text('description')->nullable();
            $table->string('operation_id')->nullable();
            $table->json('parameters')->nullable();
            $table->json('request_schema')->nullable();
            $table->json('response_schema')->nullable();
            $table->json('example')->nullable();
            $table->timestamps();

            $table->unique(['api_id', 'method', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_endpoints');
        Schema::dropIfExists('apis');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('providers');
    }
};
