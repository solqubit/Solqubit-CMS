<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Post::withTrashed()->exists()) {
            throw new \RuntimeException('Los ejemplos solo se importan en un blog sin entradas.');
        }
        $admin = User::where('role', 'admin')->where('is_active', true)->firstOrFail();
        DB::transaction(function () use ($admin) {
            $assets = [];
            foreach (json_decode(file_get_contents(__DIR__.'/data/demo.json'), true, 512, JSON_THROW_ON_ERROR) as $data) {
                $category = Category::firstOrCreate(['slug' => Str::slug($data['category'])], ['name' => $data['category'], 'tone' => $data['tone']]);
                $cover = $assets[$data['cover']] ??= $this->asset($data['cover'], '', 'Ilustración editorial');
                $blocks = $data['content'];
                foreach ($blocks as &$block) {
                    if ($block['type'] === 'image') {
                        $asset = $block['data']['asset'];
                        $media = $assets[$asset] ??= $this->asset($asset, $block['data']['alt'], $block['data']['caption']);
                        $block['data'] = ['media_id' => $media->id, 'caption' => $block['data']['caption']];
                    } elseif ($block['type'] === 'file') {
                        $uuid = (string) Str::uuid();
                        $path = 'demo/'.$uuid.'.'.pathinfo($block['data']['filename'], PATHINFO_EXTENSION);
                        Storage::disk('media')->put($path, $block['data']['contents']);
                        $media = Media::create(['uuid' => $uuid, 'path' => $path, 'original_name' => $block['data']['filename'], 'mime_type' => 'text/plain', 'size' => strlen($block['data']['contents']), 'user_id' => $admin->id]);
                        $block['data'] = ['media_id' => $media->id, 'label' => $block['data']['label']];
                    }
                }
                unset($block);
                $post = Post::create(collect($data)->only(['slug', 'title', 'excerpt', 'reading_minutes', 'difficulty', 'status', 'featured'])->all() + ['category_id' => $category->id, 'cover_id' => $cover->id, 'user_id' => $admin->id, 'content' => $blocks, 'published_at' => now()->subDay()]);
                $post->tags()->sync(array_map(fn ($tag) => Tag::firstOrCreate(['slug' => Str::slug($tag)], ['name' => $tag])->id, $data['tags']));
            }
            $linux = Series::firstOrCreate(['slug' => 'linux-y-conexiones'], ['name' => 'Linux y conexiones', 'description' => 'Comandos y referencias para conocer tu entorno y conectarte a tus equipos.', 'tone' => 'blue']);
            Post::whereIn('slug', ['linux', 'ssh'])->update(['series_id' => $linux->id]);
            $infra = Series::firstOrCreate(['slug' => 'tu-laboratorio'], ['name' => 'Tu laboratorio', 'description' => 'Material para preparar, organizar y monitorear tus servicios.', 'tone' => 'peach']);
            Post::whereIn('slug', ['nagios', 'glpi', 'docker', 'cloudpanel'])->update(['series_id' => $infra->id]);
        });
    }

    private function asset(string $asset, string $alt, string $caption): Media
    {
        $uuid = (string) Str::uuid();
        $source = public_path('themes/solqubit/assets/'.$asset);
        $path = 'demo/'.$uuid.'.'.pathinfo($source, PATHINFO_EXTENSION);
        Storage::disk('media')->put($path, file_get_contents($source));

        return Media::create(['uuid' => $uuid, 'path' => $path, 'original_name' => basename($source), 'mime_type' => str_ends_with($source, '.svg') ? 'image/svg+xml' : 'image/webp', 'size' => filesize($source), 'alt_text' => $alt, 'caption' => $caption]);
    }
}
