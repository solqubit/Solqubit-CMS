<?php

namespace App\Filament\Concerns;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

trait HasWritingWorkspace
{
    public function getExtraBodyAttributes(): array
    {
        return ['class' => 'sq-writing-page'];
    }

    public function getHeader(): ?View
    {
        $record = property_exists($this, 'record') && $this->record instanceof Model ? $this->record : null;
        $isPost = static::getResource()::getModel() === Post::class;

        return view('filament.writing-header', [
            'isPost' => $isPost,
            'primaryLabel' => $isPost ? PostResource::primaryActionLabel($record?->status ?? 'draft', $record?->published_at, $record) : 'Guardar',
            'wasPublished' => $record instanceof Post && $record->isPublished(),
            'backUrl' => static::getResource()::getUrl('index'),
            'backLabel' => static::getResource()::getPluralModelLabel(),
            'previewContext' => $isPost ? 'post' : 'page',
            'previewRecordId' => $record?->getKey(),
        ]);
    }
}
