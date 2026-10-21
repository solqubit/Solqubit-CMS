<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Monetization
{
    public const STATUSES = [
        'off' => 'Desactivada',
        'verification' => 'Preparar verificación',
        'active' => 'Mostrar anuncios',
    ];

    public const PLACEMENTS = [
        'home_bottom' => 'Portada · donde coloques la sección Anuncio',
        'article_top' => 'Entrada · después de la portada',
        'article_bottom' => 'Entrada · al terminar el contenido',
    ];

    public function defaults(): array
    {
        return [
            'status' => 'off', 'publisher_id' => '', 'format' => 'manual',
            'home_bottom' => '', 'article_top' => '', 'article_bottom' => '',
            'privacy_ready' => false,
        ];
    }

    public function configuration(): array
    {
        $saved = Setting::read('monetization', []);

        return array_replace($this->defaults(), array_intersect_key(is_array($saved) ? $saved : [], $this->defaults()));
    }

    private function validator(array $data): \Illuminate\Validation\Validator
    {
        $rules = [
            'data.status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'data.publisher_id' => ['nullable', 'string', 'regex:/^ca-pub-[0-9]{16}$/D', 'required_unless:data.status,off'],
            'data.format' => ['required', Rule::in(['manual', 'automatic'])],
            'data.privacy_ready' => ['required', 'boolean', 'accepted_if:data.status,active'],
        ];
        foreach (array_keys(self::PLACEMENTS) as $placement) {
            $rules['data.'.$placement] = ['nullable', 'string', 'regex:/^[0-9]{5,20}$/D'];
        }

        $validator = Validator::make(['data' => $data], $rules, [
            'data.publisher_id.required_unless' => 'Añade el identificador de tu cuenta para preparar la verificación o mostrar anuncios.',
            'data.publisher_id.regex' => 'Usa ca-pub- seguido de los 16 números de tu cuenta. No pegues código HTML.',
            'data.*.regex' => 'Introduce solo el identificador numérico del bloque de AdSense, sin código HTML.',
            'data.privacy_ready.accepted_if' => 'Confirma la aprobación de tu sitio y la configuración de privacidad antes de mostrar anuncios.',
        ]);
        $validator->after(function ($validator) use ($data): void {
            if (($data['status'] ?? '') === 'active' && ($data['format'] ?? '') === 'manual'
                && ! collect(array_keys(self::PLACEMENTS))->contains(fn (string $key): bool => filled($data[$key] ?? null))) {
                $validator->errors()->add('data.home_bottom', 'Añade al menos un bloque de AdSense o elige Anuncios automáticos.');
            }
        });

        return $validator;
    }

    public function save(array $input): void
    {
        $data = array_replace($this->defaults(), array_intersect_key($input, $this->defaults()));
        foreach (['publisher_id', ...array_keys(self::PLACEMENTS)] as $key) {
            if (is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }
        if (is_string($data['publisher_id']) && preg_match('/^(?:pub-)?([0-9]{16})$/D', $data['publisher_id'], $matches)) {
            $data['publisher_id'] = 'ca-pub-'.$matches[1];
        }
        $data = $this->validator($data)->validate()['data'];
        $data['privacy_ready'] = $data['status'] === 'active' && (bool) $data['privacy_ready'];
        DB::transaction(function () use ($data): void {
            Setting::write('monetization', $data);
            AuditEvent::record('monetization.updated', null, ['status' => $data['status'], 'format' => $data['format']]);
        });
    }

    public function forPage(bool $eligible, bool $preview = false): ?array
    {
        $data = $this->configuration();
        if ($preview || $data['status'] === 'off' || $this->validator($data)->fails()) {
            return null;
        }
        $slots = array_filter(array_intersect_key($data, self::PLACEMENTS), fn ($value): bool => filled($value));

        return [
            'publisher_id' => $data['publisher_id'],
            'enabled' => $eligible && $data['status'] === 'active',
            'format' => $data['format'],
            'slots' => $data['format'] === 'manual' ? $slots : [],
        ];
    }

    public function adsTxt(): ?string
    {
        $configuration = $this->forPage(false);

        return $configuration ? 'google.com, '.substr($configuration['publisher_id'], 3).", DIRECT, f08c47fec0942fa0\n" : null;
    }
}
