<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Forms\Components\ContentEditor;
use App\Models\Category;
use App\Models\EditorDraft;
use App\Models\Page as SitePage;
use App\Models\Post;
use App\Services\HomeSections;
use App\Services\Monetization;
use Filament\Actions\Action;
use Filament\Forms\Components as Forms;
use Filament\Forms\Components\Builder\Block;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/** The blog's front page as sections the administrator adds, hides and reorders, next to a live preview of the real page. */
class HomeSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Portada';

    protected static ?string $title = 'Portada del blog';

    protected static string|\UnitEnum|null $navigationGroup = 'Sitio';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'portada';

    protected string $view = 'filament.pages.home-settings';

    public ?array $data = [];

    public ?SitePage $home = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * Section types the administrator may add. The ad section only makes sense once monetization is set up.
     *
     * @return array<int, string>
     */
    public static function availableSectionTypes(): array
    {
        $types = HomeSections::TYPES;

        return app(Monetization::class)->configuration()['status'] === 'off' ? array_values(array_diff($types, ['ad'])) : $types;
    }

    /** The title shown for a chosen featured post, even when it is older than the listed options. */
    public static function postLabel(mixed $id): ?string
    {
        return filled($id) ? Post::whereKey((int) $id)->value('title') : null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->home = SitePage::where('kind', 'home')->firstOrFail();
        $sections = app(HomeSections::class);
        $this->form->fill([
            'sections' => $sections->toBuilder($sections->fromPage($this->home)),
            'seo_title' => $this->home->seo_title,
            'seo_description' => $this->home->seo_description,
        ]);
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->model($this->home)->statePath('data')->components([
            Grid::make(['default' => 1, 'xl' => 2])->schema([
                Group::make([
                    Forms\Builder::make('sections')->label('Secciones de la portada')->blocks($this->blocks())
                        ->reorderableWithDragAndDrop()->collapsible()->collapsed()->cloneable(false)
                        ->blockNumbers(false)->addBetweenAction(fn (Action $action): Action => $action->hidden())->addActionLabel('Añadir sección')->blockPickerColumns(1)
                        ->helperText('Se muestran en este orden. Arrastra una sección para moverla o desactiva «Mostrar en la portada» para ocultarla sin borrarla.'),
                ])->columnSpan(1),
                Group::make([
                    View::make('filament.home-preview')->viewData(['recordId' => $this->home?->getKey()]),
                ])->columnSpan(1),
            ]),
            Section::make('Cómo aparece en buscadores')->description('Opcional. En blanco se usan el nombre y la descripción del sitio.')->collapsible()->collapsed()->schema([
                Forms\TextInput::make('seo_title')->label('Título para buscadores')->maxLength(255),
                Forms\Textarea::make('seo_description')->label('Descripción para buscadores')->rows(3)->maxLength(320),
            ]),
        ]);
    }

    /** @return array<int, Block> */
    private function blocks(): array
    {
        $visible = fn (): Forms\Toggle => Forms\Toggle::make('visible')->label('Mostrar en la portada')->default(true);
        $label = fn (string $type, ?callable $summary = null): \Closure => function (?array $state) use ($type, $summary): string {
            $name = HomeSections::LABELS[$type];
            if ($state === null) {
                return $name;
            }
            $detail = $summary ? trim((string) $summary($state)) : '';

            return $name.($detail !== '' ? ' · '.$detail : '').(($state['visible'] ?? true) ? '' : ' · Oculta');
        };

        $blocks = [
            'welcome' => Block::make('welcome')->label($label('welcome', fn (array $state): string => $state['heading'] ?? ''))->icon('heroicon-o-hand-raised')->schema([
                $visible(),
                Forms\Toggle::make('show_heading')->label('Mostrar título')->default(true),
                Forms\TextInput::make('heading')->label('Primera línea')->maxLength(180),
                Forms\TextInput::make('accent_heading')->label('Segunda línea de color (opcional)')->maxLength(180),
                Forms\Toggle::make('show_intro')->label('Mostrar mensaje')->default(true),
                Forms\Textarea::make('intro')->label('Mensaje')->rows(3)->maxLength(1500),
                Forms\Select::make('icon')->label('Icono del mensaje')->options(SitePage::iconOptions())->default('none')->selectablePlaceholder(false),
            ]),
            'featured' => Block::make('featured')->label($label('featured', fn (array $state): string => filled($state['post_id'] ?? null) ? (string) Post::whereKey($state['post_id'])->value('title') : 'La más reciente'))
                ->icon('heroicon-o-star')->maxItems(1)->schema([
                    $visible(),
                    Forms\Select::make('post_id')->label('Qué entrada')->placeholder('La más reciente')->searchable()
                        ->options(fn (): array => Post::where('status', 'published')->latest('published_at')->limit(100)->pluck('title', 'id')->all())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => static::postLabel($value))
                        ->getSearchResultsUsing(fn (string $search): array => Post::where('status', 'published')->where('title', 'like', '%'.$search.'%')->limit(50)->pluck('title', 'id')->all())
                        ->helperText('Una entrada programada aparecerá cuando se publique.'),
                    Forms\Toggle::make('fallback_latest')->label('Si no eliges ninguna, mostrar la más reciente')->default(true),
                ]),
            'latest' => Block::make('latest')->label($label('latest', fn (array $state): string => ($state['per_page'] ?? 12).' por página'))->icon('heroicon-o-squares-2x2')->maxItems(1)->schema([
                $visible(),
                Forms\TextInput::make('title')->label('Título sobre las entradas')->placeholder('Últimas entradas.')->maxLength(180),
                Forms\Select::make('per_page')->label('Entradas por página')->options(array_combine(HomeSections::PER_PAGE, HomeSections::PER_PAGE))->default(12)->required()->selectablePlaceholder(false)
                    ->helperText('Al superar esta cantidad se muestran páginas numeradas.'),
                Forms\Toggle::make('show_search')->label('Mostrar el buscador')->default(true),
                Forms\Toggle::make('show_filter')->label('Mostrar el filtro por tema')->default(true),
            ]),
            'topic' => Block::make('topic')->label($label('topic', fn (array $state): string => filled($state['category_id'] ?? null) ? (string) Category::whereKey($state['category_id'])->value('name').' · '.($state['count'] ?? 3).' entradas' : ''))
                ->icon('heroicon-o-tag')->schema([
                    $visible(),
                    Forms\Select::make('category_id')->label('Categoría')->options(fn (): array => Category::orderBy('name')->pluck('name', 'id')->all())->required()->searchable(),
                    Forms\TextInput::make('title')->label('Título (opcional)')->placeholder('El nombre de la categoría')->maxLength(180),
                    Forms\Select::make('count')->label('Cuántas entradas')->options([3 => '3', 6 => '6', 9 => '9'])->default(3)->required()->selectablePlaceholder(false),
                ]),
            'series' => Block::make('series')->label($label('series', fn (array $state): string => ($state['count'] ?? 3).' series'))->icon('heroicon-o-rectangle-stack')->schema([
                $visible(),
                Forms\TextInput::make('title')->label('Título (opcional)')->placeholder('Series')->maxLength(180),
                Forms\Select::make('count')->label('Cuántas series')->options([3 => '3', 6 => '6'])->default(3)->required()->selectablePlaceholder(false),
            ]),
            'text' => Block::make('text')->label($label('text'))->icon('heroicon-o-document-text')->schema([
                $visible(),
                ContentEditor::make('content')->label('Texto')->hiddenLabel()->editorContext('page')->minHeight('200px')->withoutDrafts(),
            ]),
            'ad' => Block::make('ad')->label($label('ad'))->icon('heroicon-o-megaphone')->maxItems(1)->schema([
                $visible(),
                Text::make('Muestra el anuncio de la portada que configuraste en Monetización, en el lugar donde coloques esta sección.'),
            ]),
        ];

        return array_values(array_intersect_key($blocks, array_flip(static::availableSectionTypes())));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Ver la portada')->color('gray')->icon('heroicon-o-arrow-top-right-on-square')->url(fn (): string => route('home'))->openUrlInNewTab(),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $homeSections = app(HomeSections::class);
        try {
            $sections = $homeSections->validate($homeSections->fromBuilder($data['sections'] ?? []));
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(['data.sections' => collect($error->errors())->flatten()->first()]);
        }
        $welcome = collect($sections)->firstWhere('type', 'welcome') ?? [];
        $text = collect($sections)->first(fn (array $section): bool => $section['type'] === 'text' && $section['visible']);
        $latest = collect($sections)->firstWhere('type', 'latest');
        // Older readers (search-engine fallbacks, the welcome sample) keep finding the welcome text in its usual keys.
        $this->home->settings = array_replace($this->home->settings ?? [],
            array_intersect_key($welcome, array_flip(['heading', 'accent_heading', 'intro', 'icon', 'show_heading', 'show_intro'])),
            $latest ? ['catalog_title' => $latest['title'], 'per_page' => $latest['per_page']] : [],
            ['sections' => $sections]);
        $this->home->content = $text['content'] ?? [];
        $this->home->seo_title = $data['seo_title'] ?? null;
        $this->home->seo_description = $data['seo_description'] ?? null;
        $this->home->save();
        EditorDraft::clearFor('page', $this->home->id);
        $this->dispatch('editor-saved', context: 'page', recordId: $this->home->id);
        $this->rememberData();
        Notification::make()->title('Portada guardada')->success()->send();
    }
}
