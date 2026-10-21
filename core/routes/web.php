<?php

use App\Http\Controllers\AdminTransferController;
use App\Http\Controllers\AdsTxtController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\EditorDraftController;
use App\Http\Controllers\EditorPreviewController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\VisitController;
use App\Http\Middleware\RedirectTrailingSlash;
use App\Services\AdminAccess;
use App\Services\PostPermalinks;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/install', [InstallController::class, 'index'])->name('install.index');
Route::post('/install', [InstallController::class, 'store'])->middleware('throttle:installer')->name('install.store');
Route::post('/install/check-database', [InstallController::class, 'checkDatabase'])->middleware('throttle:installer')->name('install.database');
Route::get('/', [BlogController::class, 'index'])->name('home');
Route::get('/preview/{post}', [BlogController::class, 'preview'])->middleware('auth')->name('posts.preview');
Route::get('/media/{media:uuid}', [MediaController::class, 'show'])->name('media.show');
Route::prefix(AdminAccess::path())->middleware('auth')->group(function (): void {
    Route::get('/downloads/export', [AdminTransferController::class, 'export'])->name('admin.export');
    Route::post('/actualizaciones/package', [UpdateController::class, 'upload'])->middleware('throttle:5,1')->name('update.upload');
    Route::post('/actualizaciones/apply', [UpdateController::class, 'apply'])->middleware('throttle:3,1')->name('update.apply');
    Route::post('/actualizaciones/files', [UpdateController::class, 'verifyFiles'])->middleware('throttle:3,1')->name('update.files');
    Route::post('/actualizaciones/download', [UpdateController::class, 'download'])->middleware('throttle:3,1')->name('update.download');
    Route::post('/actualizaciones/check', [UpdateController::class, 'check'])->middleware('throttle:1,1')->name('update.check');
    Route::post('/editor-preview', EditorPreviewController::class)->middleware('throttle:120,1')->name('editor.preview');
    Route::match(['get', 'put', 'delete'], '/editor-draft', EditorDraftController::class)->middleware('throttle:120,1')->name('editor.draft');
});
Route::get('/preview/pagina/{page}', [BlogController::class, 'previewPage'])->middleware('auth')->name('pages.preview');

// Read-only machine files: no session, CSRF token or queued cookies, so they can be cached publicly.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class])->group(function (): void {
    Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
    Route::get('/sitemap-{page}.xml', [SeoController::class, 'sitemapPage'])->whereNumber('page')->name('sitemap.page');
    Route::get('/feed.xml', [BlogController::class, 'feed'])->name('feed');
    Route::get('/ads.txt', AdsTxtController::class)->name('ads.txt');
    Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
});

// Page views sent by visitas.js: no session, cookies or CSRF token, so counting never touches a visitor's session.
Route::post('/visita', VisitController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class])
    ->name('visits.store');

// Public pages keep the session (signed-in editors see admin links); "/address/" answers with a 301 to "/address".
Route::middleware(RedirectTrailingSlash::class)->group(function (): void {
    Route::get('/series', [BlogController::class, 'series'])->name('series');
    Route::get('/acerca', [BlogController::class, 'about'])->name('about');
    Route::get('/pagina/{slug}', [BlogController::class, 'legacyPage'])->name('pages.legacy');
    Route::get('/{slug}', [BlogController::class, 'custom'])->where('slug', PostPermalinks::routePattern(false))->name('pages.show');
    Route::get('/{path}', [BlogController::class, 'show'])->where('path', PostPermalinks::routePattern())->name('posts.show');
});
