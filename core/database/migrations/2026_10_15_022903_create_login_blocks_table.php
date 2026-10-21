<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('login_blocks', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->string('ip', 45)->index();
            $table->string('scope', 20);
            $table->text('email')->nullable();
            $table->unsignedInteger('attempts');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_blocks');
    }
};
