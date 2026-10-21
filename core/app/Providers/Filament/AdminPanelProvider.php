<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Auth\Profile;
use App\Filament\Auth\RequestPasswordReset;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\RequireAdminTwoFactor;
use App\Models\Setting;
use App\Services\AdminAccess;
use App\Services\AdminSearch;
use App\Services\SiteIdentity;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Two-step verification is registered as "required" so Filament adds its set-up route and page
     * middleware, but {@see RequireAdminTwoFactor} only enforces it for administrators while the
     * access setting is on. This keeps the decision at request time, also with cached routes.
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path(AdminAccess::path())
            ->login(Login::class)
            ->profile(Profile::class)
            ->passwordReset(RequestPasswordReset::class)
            ->multiFactorAuthentication([AppAuthentication::make()->codeWindow(1)->recoverable()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireAdminTwoFactor::class)
            ->brandName(fn (): string => (Setting::read('site', [])['name'] ?? 'Solqubit').' · Administración')
            ->defaultThemeMode(ThemeMode::Light)
            ->favicon(fn (): string => app(SiteIdentity::class)->faviconUrl())
            ->databaseTransactions()
            ->unsavedChangesAlerts()
            ->globalSearch(AdminSearch::class)
            ->bootUsing(fn () => FilamentTimezone::set(fn (): string => Setting::read('site', [])['timezone'] ?? 'UTC'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => '<link rel="stylesheet" href="'.e(asset('editor/writing.css')).'?v='.filemtime(public_path('editor/writing.css')).'">')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => '<link rel="stylesheet" href="'.e(asset('editor/editor.css')).'?v='.filemtime(public_path('editor/editor.css')).'"><link rel="stylesheet" href="'.e(asset('editor/admin.css')).'?v='.filemtime(public_path('editor/admin.css')).'">')
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => '<script type="module" src="'.e(asset('editor/live-preview.js')).'?v='.filemtime(public_path('editor/live-preview.js')).'"></script>'
                .'<script type="module" src="'.e(asset('editor/block-inserter.js')).'?v='.filemtime(public_path('editor/block-inserter.js')).'"></script>')
            ->colors([
                'primary' => Color::Violet,
            ])
            ->font('DM Sans', url: fn (): string => asset('themes/solqubit/fonts/panel.css').'?v='.filemtime(public_path('themes/solqubit/fonts/panel.css')), provider: LocalFontProvider::class)
            ->navigationGroups(['Contenido', 'Administración', 'Sitio', 'Publicación', 'Sistema'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                EnsureInstalled::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
