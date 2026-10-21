<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw visits live only until their day is closed and summarised into visit_stats.
     */
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table): void {
            $table->id();
            $table->date('day')->index();
            $table->string('path', 255);
            $table->char('visitor', 16);
            $table->boolean('is_returning')->default(false);
            $table->string('country', 2)->default('');
            $table->string('source', 80)->default('');
            $table->string('device', 10)->default('');
            $table->string('browser', 20)->default('');
            $table->string('os', 20)->default('');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('visit_stats', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('dimension', 10);
            $table->string('value', 255)->default('');
            $table->unsignedInteger('views');
            $table->unsignedInteger('visitors');
            $table->unsignedInteger('returning_visitors')->default(0);
            $table->unique(['day', 'dimension', 'value']);
            $table->index(['dimension', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_stats');
        Schema::dropIfExists('visits');
    }
};
