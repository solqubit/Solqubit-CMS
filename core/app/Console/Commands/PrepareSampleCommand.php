<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Services\WelcomeSample;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('solqubit:sample {--replace-bundled : Sustituye solo los ejemplos originales sin editar}')]
#[Description('Crea una única entrada de muestra con todos los bloques')]
class PrepareSampleCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(WelcomeSample $sample): int
    {
        $posts = Post::withTrashed()->get();
        if ($posts->count() === 1 && $posts->first()->slug === 'entrada-de-muestra') {
            $this->info('La entrada de muestra ya existe; se conserva.');

            return self::SUCCESS;
        }
        $seed = collect(json_decode(file_get_contents(database_path('seeders/data/demo.json')), true, 512, JSON_THROW_ON_ERROR))->keyBy('slug');
        if ($posts->isNotEmpty()) {
            if (! $this->option('replace-bundled')) {
                $this->error('El blog contiene entradas. No se han modificado.');

                return self::FAILURE;
            }
            foreach ($posts as $post) {
                $original = $seed->get($post->slug);
                $snapshot = $post->revisions()->oldest('id')->first()?->snapshot;
                $tags = $post->tags()->pluck('name')->sort()->values()->all();
                if (! $original || ! $snapshot || $post->revisions()->count() !== 1 || $post->title !== $original['title'] || $post->excerpt !== $original['excerpt'] || $post->content !== $snapshot['content'] || $post->status !== $original['status'] || $tags !== collect($original['tags'])->sort()->values()->all()) {
                    $this->error('Hay contenido nuevo o editado. Se conserva todo; sustituye las entradas desde el panel.');

                    return self::FAILURE;
                }
            }
        }
        DB::transaction(function () use ($sample, $posts, $seed): void {
            foreach ($posts as $post) {
                $post->forceDelete();
            }
            Category::whereIn('name', $seed->pluck('category')->unique())->whereDoesntHave('posts')->delete();
            Tag::whereIn('name', $seed->pluck('tags')->flatten()->unique())->whereDoesntHave('posts')->delete();
            Series::whereIn('slug', ['linux-y-conexiones', 'tu-laboratorio'])->whereDoesntHave('posts')->delete();
            $sample->create(User::where('role', 'admin')->where('is_active', true)->firstOrFail());
        });
        foreach (Media::where('path', 'like', 'demo/%')->get() as $media) {
            if (! $media->isReferenced()) {
                $media->delete();
            }
        }
        $this->info('Lista una única entrada de muestra; los usuarios y la configuración se conservan.');

        return self::SUCCESS;
    }
}
