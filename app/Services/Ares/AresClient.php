<?php

namespace App\Services\Ares;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klient veřejného ARES REST API (ares.gov.cz) — načtení údajů firmy podle IČO.
 */
class AresClient
{
    /**
     * @return array{name: string, ico: string, dic: ?string, street: ?string, city: ?string, zip: ?string}|null
     */
    public function lookup(string $ico): ?array
    {
        $ico = preg_replace('/\D/', '', $ico);

        if (strlen((string) $ico) !== 8) {
            return null;
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get(config('ucetni_prehled.ares.base_url')."/ekonomicke-subjekty/{$ico}");
        } catch (\Throwable $e) {
            Log::warning('ARES dotaz selhal: '.$e->getMessage(), ['ico' => $ico]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $sidlo = $data['sidlo'] ?? [];

        return [
            'name' => $data['obchodniJmeno'] ?? '',
            'ico' => $data['ico'] ?? $ico,
            'dic' => $data['dic'] ?? null,
            'street' => $this->buildStreet($sidlo),
            'city' => $sidlo['nazevObce'] ?? null,
            'zip' => isset($sidlo['psc']) ? (string) $sidlo['psc'] : null,
        ];
    }

    private function buildStreet(array $sidlo): ?string
    {
        $street = $sidlo['nazevUlice'] ?? $sidlo['nazevObce'] ?? null;

        if ($street === null) {
            return null;
        }

        $house = $sidlo['cisloDomovni'] ?? null;
        $orientation = $sidlo['cisloOrientacni'] ?? null;

        $numbers = $house !== null
            ? $house.($orientation !== null ? '/'.$orientation : '')
            : null;

        return trim($street.' '.$numbers);
    }
}
