<?php

namespace App\Http\Controllers;

use App\Services\InstallationDatabase;
use App\Services\Installer;
use App\Services\Requirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class InstallController extends Controller
{
    public function index(Requirements $requirements)
    {
        if (is_file(config('solqubit.installed_file'))) {
            return view('install.completed');
        }

        $checks = $requirements->check();

        return view('install.index', ['checks' => $checks, 'ready' => ! in_array(false, array_column($checks, 'ok'), true)]);
    }

    public function checkDatabase(Request $request, InstallationDatabase $database): JsonResponse
    {
        abort_if(is_file(config('solqubit.installed_file')), 404);
        $result = $database->check($request->validate($this->databaseRules()));

        return response()->json(['ok' => true, 'message' => $result['resuming']
            ? 'Conexión correcta. Puedes reanudar la instalación con el mismo administrador.'
            : 'Conexión correcta. El servidor es compatible y la base está vacía.']);
    }

    public function store(Request $request, Installer $installer)
    {
        abort_if(is_file(config('solqubit.installed_file')), 404);
        $data = $request->validate($this->databaseRules() + [
            'site_name' => ['required', 'string', 'max:100'],
            'timezone' => ['required', Rule::in(\DateTimeZone::listIdentifiers())],
            'name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            'starter_content' => ['required', Rule::in(['empty', 'sample'])],
        ]);
        $data['site_url'] = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
        $installer->install($data);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('filament.admin.auth.login');
    }

    private function databaseRules(): array
    {
        return [
            'db_host' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.\-:]+$/'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_\-]+$/'],
            'db_username' => ['required', 'string', 'max:80'],
            'db_password' => ['required', 'string', 'max:255'],
        ];
    }
}
