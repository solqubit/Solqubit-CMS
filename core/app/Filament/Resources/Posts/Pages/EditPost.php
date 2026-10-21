<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Concerns\HasWritingWorkspace;
use App\Filament\Resources\Posts\PostResource;
use App\Models\AuditEvent;
use App\Models\EditorDraft;
use App\Models\Tag;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    use HasWritingWorkspace;

    protected static string $resource = PostResource::class;

    protected ?string $heading = 'Editar entrada';

    public static bool $formActionsAreSticky = true;

    protected function afterSave(): void
    {
        EditorDraft::clearFor('post', $this->record->id);
        $this->record->syncTagNames($this->data['tags'] ?? []);
        $this->record->rememberRevisionTags();
        $this->dispatch('editor-saved', context: 'post', recordId: $this->record->id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        PostResource::ensureUsableMedia($data, $this->record);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                PostResource::duplicateAction(),
                Actions\Action::make('revision')->label('Recuperar revisión')->icon('heroicon-o-clock')->schema([
                    Select::make('revision_id')->label('Versión anterior')->options(fn () => $this->record->revisions()->with('author')->get()->mapWithKeys(fn ($revision) => [$revision->id => '#'.$revision->id.' · '.$revision->created_at->format('d/m/Y H:i:s').' · '.($revision->author?->name ?? 'Sistema')]))->required(),
                ])->modalDescription('La versión elegida se carga en el editor sin guardar nada. Revísala y pulsa Guardar para conservarla. La dirección, el estado y la fecha de publicación no cambian.')
                    ->modalSubmitActionLabel('Cargar en el editor')->action(function (array $data): void {
                        $revision = $this->record->revisions()->findOrFail($data['revision_id']);
                        $restored = $this->record->revisionFormData($revision);
                        $this->form->fill([...$this->record->attributesToArray(), ...$restored['data']]);
                        $this->data['tags'] = Tag::whereKey($restored['data']['tags'])->orderBy('name')->pluck('name')->all();
                        AuditEvent::record('post.revision_loaded', (string) $this->record->id, ['revision_id' => $revision->id]);
                        Notification::make()->success()->title('Revisión cargada en el editor')
                            ->body(($restored['missing_media'] ? $restored['missing_media'].' archivo(s) de esa versión ya no existe(n) en la biblioteca y se omitieron. ' : '').'Pulsa Guardar para conservarla.')->send();
                    }),
                Actions\DeleteAction::make(), Actions\RestoreAction::make(), Actions\ForceDeleteAction::make()->label('Eliminar definitivamente'),
            ])->label('Más opciones')->icon('heroicon-o-ellipsis-horizontal')->color('gray')->button(),
        ];
    }
}
