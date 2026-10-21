<?php

namespace App\Http\Controllers;

use App\Services\VisitTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VisitController extends Controller
{
    /**
     * Receive the page view sent by visitas.js. Always answers 204, so the result reveals nothing.
     */
    public function __invoke(Request $request, VisitTracker $tracker): Response
    {
        $tracker->record($request);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
