<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $appearance = json_decode(DB::table('settings')->where('key', 'appearance')->value('value') ?? '{}', true);
        if (! is_array($appearance) || (! array_key_exists('palettes', $appearance) && ! array_key_exists('active', $appearance))) {
            return;
        }

        DB::table('settings')->where('key', 'appearance')->update([
            'value' => json_encode(['variant' => 'default'], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Removed custom palettes are not recreated when rolling back.
    }
};
