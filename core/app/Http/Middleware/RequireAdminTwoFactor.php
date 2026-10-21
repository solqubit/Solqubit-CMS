<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AdminAccess;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends administrators without two-step verification to Filament's set-up page while the
 * "require two-step verification" access setting is on. Editors, and every user while the
 * setting is off, continue as usual.
 */
class RequireAdminTwoFactor
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User || ! $user->isAdmin() || ! app(AdminAccess::class)->requiresAdminTwoFactor()) {
            return $next($request);
        }

        return app(EnsureMultiFactorAuthenticationIsEnabled::class)->handle($request, $next);
    }
}
