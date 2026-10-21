<?php

use App\Models\Media;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store image dimensions so pages can reserve space (width/height) and offer srcset candidates.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
        });
        DB::table('media')->select(['id', 'path', 'mime_type'])->whereIn('mime_type', Media::RASTER_MIMES)->orderBy('id')
            ->chunkById(100, function ($items): void {
                foreach ($items as $item) {
                    $dimensions = Media::readDimensions($item->path, $item->mime_type);
                    if ($dimensions) {
                        DB::table('media')->where('id', $item->id)->update(['width' => $dimensions[0], 'height' => $dimensions[1]]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['width', 'height']);
        });
    }
};
