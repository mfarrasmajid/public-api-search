<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lightweight search telemetry. Enough to see which queries return
     * nothing, which is the main signal for tuning relevance in the POC.
     */
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->string('query');
            $table->json('filters')->nullable();
            $table->unsignedInteger('total_hits')->default(0);
            $table->unsignedInteger('took_ms')->default(0);
            $table->string('driver')->default('opensearch'); // opensearch|database
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
