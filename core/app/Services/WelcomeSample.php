<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WelcomeSample
{
    public function create(User $author): Post
    {
        if (Post::withTrashed()->exists()) {
            throw new \RuntimeException('La muestra inicial solo se crea en un blog sin entradas.');
        }

        return DB::transaction(fn (): Post => Post::create($this->attributes($author) + [
            'user_id' => $author->id, 'slug' => 'entrada-de-muestra', 'status' => 'published', 'published_at' => now(),
        ]));
    }

    public function refreshExample(Post $post): Post
    {
        if ($post->slug !== 'entrada-de-muestra') {
            throw new \RuntimeException('Solo se puede renovar la entrada de muestra.');
        }

        return DB::transaction(function () use ($post): Post {
            $post->update($this->attributes($post->author));

            return $post->refresh();
        });
    }

    private function attributes(User $author): array
    {
        $image = $this->media($author, 'universo-ilustrado.webp', 'image/webp', file_get_contents(database_path('seeders/data/universe-cover.webp')), 'Ilustración de una sonda, un planeta oceánico imaginario y una luna helada');
        $file = $this->media($author, 'cuaderno-del-universo.txt', 'text/plain', file_get_contents(database_path('seeders/data/universe-notes.txt')));
        $video = $this->media($author, 'orbitas-ilustradas.mp4', 'video/mp4', file_get_contents(database_path('seeders/data/universe-orbits.mp4')));
        $content = require database_path('seeders/data/universe-article.php');

        return [
            'title' => '¿Hay vida más allá de la Tierra?',
            'excerpt' => 'Entre mundos oceánicos, estrellas lejanas y Proyecto fin del mundo: una mirada a lo que sabemos, lo que buscamos y lo que todavía imaginamos.',
            'featured' => true, 'cover_id' => $image->id, 'reading_minutes' => 7, 'difficulty' => 'Divulgación',
            'seo_description' => 'Explora la búsqueda de vida extraterrestre y su conexión con Proyecto fin del mundo. Ciencia, ficción y fuentes para seguir investigando.',
            'content' => $content($image->id, $file->id, $video->id),
        ];
    }

    private function media(User $author, string $name, string $mime, string $contents, string $alt = ''): Media
    {
        $uuid = (string) Str::uuid();
        $path = 'files/'.date('Y/m').'/'.$uuid.'.'.pathinfo($name, PATHINFO_EXTENSION);
        Storage::disk('media')->put($path, $contents);

        return Media::create(['uuid' => $uuid, 'path' => $path, 'original_name' => $name, 'mime_type' => $mime, 'size' => strlen($contents), 'alt_text' => $alt, 'user_id' => $author->id]);
    }
}
