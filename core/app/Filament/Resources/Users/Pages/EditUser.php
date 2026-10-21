<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** Model rules (such as keeping one active administrator) report on the form field instead of failing silently. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (ValidationException $error) {
            Notification::make()->danger()->title('No se guardaron los cambios')->body(collect($error->errors())->flatten()->first())->send();
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn (array $messages, string $key): array => [str_starts_with($key, 'data.') ? $key : 'data.'.$key => $messages])->all());
        }
    }

    private bool $passwordChanged = false;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->passwordChanged = filled($data['password'] ?? null);

        return $data;
    }

    /** Changing your own password here keeps your session, as it does from your profile. */
    protected function afterSave(): void
    {
        if ($this->passwordChanged && $this->record->is(auth()->user())) {
            session()->put('password_hash_'.Filament::getAuthGuard(), $this->record->fresh()->getAuthPassword());
        }
    }
}
