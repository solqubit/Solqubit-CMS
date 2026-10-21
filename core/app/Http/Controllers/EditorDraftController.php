<?php

namespace App\Http\Controllers;

use App\Models\EditorDraft;
use App\Models\Page;
use App\Models\Post;
use App\Services\ContentDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditorDraftController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['context' => 'required|in:post,page', 'record_id' => 'nullable|integer|min:1']);
        $class = $data['context'] === 'post' ? Post::class : Page::class;
        $record = isset($data['record_id']) ? $class::findOrFail($data['record_id']) : null;
        abort_unless($record ? $request->user()->can('update', $record) : $request->user()->can('create', $class), 403);
        $key = ['user_id' => $request->user()->id, 'context' => $data['context'], 'record_key' => (string) ($record?->id ?? 'new')];
        $base = $record ? hash('sha256', json_encode($record->getAttributes(), JSON_THROW_ON_ERROR)) : null;
        $draft = EditorDraft::where($key)->first();
        if ($request->isMethod('get')) {
            return response()->json(['version' => $draft?->version ?? 0, 'base_revision' => $base, 'draft' => $draft && $draft->updated_at->gt(now()->subDays(30)) ? [
                'payload' => $draft->payload, 'saved_at' => $draft->updated_at->toIso8601String(), 'outdated' => $draft->base_revision !== $base,
            ] : null]);
        }
        $validated = $request->validate(['version' => 'required|integer|min:0', 'base_revision' => 'nullable|string|size:64']);
        abort_if(($validated['base_revision'] ?? null) !== $base, 409, 'La versión guardada cambió. Recarga el editor antes de continuar.');
        if ($request->isMethod('delete')) {
            DB::transaction(function () use ($key, $class, $record, $base, $validated): void {
                if ($record) {
                    $current = $class::whereKey($record->id)->lockForUpdate()->firstOrFail();
                    abort_if(hash('sha256', json_encode($current->getAttributes(), JSON_THROW_ON_ERROR)) !== $base, 409, 'La versión guardada cambió.');
                }
                $draft = EditorDraft::where($key)->lockForUpdate()->first();
                abort_if(($draft?->version ?? 0) !== $validated['version'], 409, 'El borrador cambió en otra pestaña.');
                $draft?->delete();
            });

            return response()->json(['version' => 0]);
        }
        abort_if(strlen($request->getContent()) > 2000000, 413, 'El borrador supera el tamaño admitido.');
        $input = $request->validate(['document' => 'required|array', 'fields' => 'present|array']);
        $document = app(ContentDocument::class);
        $normalized = $document->toDocument($document->toBlocks($input['document']));
        $allowed = ['title', 'slug', 'excerpt', 'url_prefix', 'cover_id', 'category_ids', 'tags', 'series_id', 'series_order', 'reading_minutes', 'difficulty', 'seo_title', 'seo_description', 'settings', 'show_updated_at'];
        $payload = ['document' => $normalized, 'fields' => Arr::only($input['fields'], $allowed)];
        try {
            $saved = DB::transaction(function () use ($key, $base, $payload, $validated, $class, $record): EditorDraft {
                if ($record) {
                    $current = $class::whereKey($record->id)->lockForUpdate()->firstOrFail();
                    abort_if(hash('sha256', json_encode($current->getAttributes(), JSON_THROW_ON_ERROR)) !== $base, 409, 'La versión guardada cambió.');
                }
                $draft = EditorDraft::where($key)->lockForUpdate()->first();
                abort_if(($draft?->version ?? 0) !== $validated['version'], 409, 'El borrador cambió en otra pestaña.');
                $draft ??= new EditorDraft($key);
                $draft->fill(['payload' => $payload, 'base_revision' => $base, 'version' => $validated['version'] + 1])->save();

                return $draft;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'El borrador cambió en otra pestaña.');
        }
        EditorDraft::where('user_id', $request->user()->id)->where('updated_at', '<', now()->subDays(30))->delete();

        return response()->json(['version' => $saved->version, 'saved_at' => $saved->updated_at->toIso8601String()]);
    }
}
