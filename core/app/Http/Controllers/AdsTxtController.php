<?php

namespace App\Http\Controllers;

use App\Services\Monetization;
use Illuminate\Http\Response;

class AdsTxtController extends Controller
{
    public function __invoke(Monetization $monetization): Response
    {
        $content = $monetization->adsTxt();
        abort_if($content === null, 404);

        return response($content)->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'no-store');
    }
}
