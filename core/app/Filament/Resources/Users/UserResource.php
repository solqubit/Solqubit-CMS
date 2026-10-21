<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Posts\PostResource;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components as Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static int $globalSearchResultsLimit = 8;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'username'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Perfil público')->description('Así aparece esta persona como autor de sus entradas.')->schema([
                Forms\TextInput::make('name')->label('Nombre')->required()->maxLength(255)->helperText('Este nombre aparece como autor en las entradas de este usuario.'),
                PostResource::mediaSelect('avatar_id', images: true)->label('Foto del autor (opcional)'),
                Forms\Textarea::make('bio')->label('Biografía breve (opcional)')->rows(3)->maxLength(500)->helperText('Se muestra junto al nombre del autor en sus entradas.'),
            ])->columnSpanFull(),
            Section::make('Acceso al panel')->description('Inicia sesión con tu correo o tu usuario y la misma contraseña. El correo también permite recuperar el acceso.')->schema([
                self::usernameField(),
                Forms\TextInput::make('email')->label('Correo')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                Forms\TextInput::make('password')->label(fn (string $operation): string => $operation === 'create' ? 'Contraseña' : 'Nueva contraseña')->password()->revealable()->rule(Password::min(12)->letters()->numbers())->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->afterStateHydrated(fn ($component) => $component->state(null))->helperText(fn (string $operation): string => $operation === 'create' ? 'Al menos 12 caracteres, con letras y números.' : 'En blanco conserva la contraseña actual. Al cambiarla, usa al menos 12 caracteres con letras y números.'),
                Forms\Select::make('role')->label('Permisos')->options(['admin' => 'Administrador', 'editor' => 'Editor de contenido'])->default('editor')->required()->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText(fn (?User $record): string => $record?->is(auth()->user()) ? 'No puedes cambiar tus propios permisos. Pídeselo a otro administrador.' : 'Administrador: configura todo el sitio y sus usuarios. Editor: trabaja con entradas, archivos, categorías, series y etiquetas.'),
                Forms\Toggle::make('is_active')->label('Permitir iniciar sesión')->default(true)->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText(fn (?User $record): string => $record?->is(auth()->user()) ? 'No puedes desactivar tu propia cuenta.' : 'Desactivarlo impide entrar al panel, pero conserva las publicaciones del autor.'),
            ])->columnSpanFull(),
        ]);
    }

    public static function usernameField(): Forms\TextInput
    {
        return Forms\TextInput::make('username')->label('Usuario para iniciar sesión')->required()->minLength(3)->maxLength(40)
            ->regex('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/')->unique(ignoreRecord: true)
            ->dehydrateStateUsing(fn (string $state): string => strtolower(trim($state)))
            ->helperText('De 3 a 40 caracteres: letras sin tildes, números, punto, guion o guion bajo. Se guarda en minúsculas y no cambia el nombre público del autor.')
            ->validationMessages(['regex' => 'Usa letras sin tildes, números, punto, guion o guion bajo; empieza con una letra o un número.']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable(), Tables\Columns\TextColumn::make('email')->label('Correo')->searchable(), Tables\Columns\TextColumn::make('role')->label('Permisos')->badge()->formatStateUsing(fn (string $state): string => $state === 'admin' ? 'Administrador' : 'Editor de contenido'), Tables\Columns\TextColumn::make('posts_count')->label('Entradas')->counts('posts')->sortable(), Tables\Columns\IconColumn::make('two_factor')->label('Dos pasos')->boolean()->getStateUsing(fn (User $record): bool => filled($record->app_authentication_secret)), Tables\Columns\IconColumn::make('is_active')->label('Activo')->boolean()])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/'), 'create' => Pages\CreateUser::route('/create'), 'edit' => Pages\EditUser::route('/{record}/edit')];
    }
}
