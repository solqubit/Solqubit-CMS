<?php

namespace App\Filament\Auth;

use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class Profile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            PostResource::mediaSelect('avatar_id', images: true)->label('Foto del autor (opcional)'),
            Textarea::make('bio')->label('Biografía breve')->maxLength(500)->rows(3)->helperText('Tu nombre, foto y biografía aparecen en tus entradas.'),
            UserResource::usernameField(),
            $this->getEmailFormComponent(), $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(), $this->getCurrentPasswordFormComponent(),
        ]);
    }
}
