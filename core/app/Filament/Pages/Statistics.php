<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Visits\TopBrowsers;
use App\Filament\Widgets\Visits\TopCountries;
use App\Filament\Widgets\Visits\TopPages;
use App\Filament\Widgets\Visits\TopSources;
use App\Filament\Widgets\Visits\TopSystems;
use App\Filament\Widgets\Visits\VisitDevices;
use App\Filament\Widgets\Visits\VisitTotals;
use App\Filament\Widgets\Visits\VisitTrend;
use App\Models\Setting;
use App\Services\CountryDatabase;
use App\Services\VisitStatistics;
use App\Services\VisitTracker;
use Filament\Actions\Action;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Throwable;

/**
 * The blog's own visit statistics. Every widget is lazy and reads cached daily summaries, so the page opens at once.
 */
class Statistics extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'estadisticas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Estadísticas';

    protected static ?string $title = 'Estadísticas de visitas';

    protected static ?int $navigationSort = -1;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function getSubheading(): ?string
    {
        if (! app(VisitTracker::class)->settings()['enabled']) {
            return 'El registro de visitas está en pausa. Puedes reanudarlo en «Ajustes».';
        }

        return 'Recuento propio, sin cookies ni servicios externos. No incluye robots ni a quien tiene la sesión iniciada. Se actualiza cada 5 minutos.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Select::make('range')->label('Periodo')->options(VisitStatistics::RANGES)->default(30)->selectablePlaceholder(false),
        ]);
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [VisitTotals::class, VisitTrend::class, TopPages::class, TopSources::class, TopCountries::class, VisitDevices::class, TopBrowsers::class, TopSystems::class];
    }

    /**
     * @return int|array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('countries')
                ->label(fn (): string => app(CountryDatabase::class)->installed() ? 'Actualizar países' : 'Activar países')
                ->icon('heroicon-o-globe-americas')->color('gray')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-globe-americas')
                ->modalHeading('Lista de países')
                ->modalDescription(function (): string {
                    $month = app(CountryDatabase::class)->month();
                    $current = $month ? 'Tienes instalada la lista de '.$month.'. ' : '';

                    return $current.'Se descargará la lista gratuita de DB-IP (unos 5 MB) para saber de qué país llega cada visita sin enviar datos a nadie. Conviene actualizarla una vez al mes.';
                })
                ->modalSubmitActionLabel('Descargar')
                ->action(function (): void {
                    try {
                        $month = app(CountryDatabase::class)->download();
                        Notification::make()->success()->title('Lista de países instalada')->body('Versión '.$month.' de DB-IP. Las nuevas visitas ya mostrarán su país.')->send();
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->danger()->title('No se pudo instalar la lista de países')->body($exception->getMessage())->send();
                    }
                }),
            Action::make('settings')
                ->label('Ajustes')->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->modalHeading('Ajustes de las estadísticas')
                ->modalSubmitActionLabel('Guardar')
                ->fillForm(fn (): array => app(VisitTracker::class)->settings())
                ->schema([
                    Forms\Toggle::make('enabled')->label('Registrar visitas')
                        ->helperText('Si lo desactivas, el blog deja de cargar el contador. Las cifras ya guardadas se conservan.'),
                    Forms\Toggle::make('returning')->label('Reconocer a los visitantes que vuelven')
                        ->helperText('El navegador del visitante guarda solo la fecha de su primera visita, sin ningún identificador. Según el país de tu audiencia, la ley puede pedir que lo menciones en tu política de privacidad.'),
                ])
                ->action(function (array $data): void {
                    Setting::write('visits', ['enabled' => (bool) $data['enabled'], 'returning' => (bool) $data['returning']]);
                    Notification::make()->success()->title('Ajustes guardados')->send();
                }),
        ];
    }
}
