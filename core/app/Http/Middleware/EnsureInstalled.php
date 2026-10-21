<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next)
    {
        if (! is_file(config('solqubit.installed_file')) && ! $request->is('install', 'install/*', 'up')) {
            return redirect()->route('install.index');
        }

        return $next($request);
    }
}
