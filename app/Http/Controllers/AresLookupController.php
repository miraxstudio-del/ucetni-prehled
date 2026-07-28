<?php

namespace App\Http\Controllers;

use App\Services\Ares\AresClient;
use Illuminate\Http\JsonResponse;

class AresLookupController extends Controller
{
    /** Načtení údajů firmy z ARES podle IČO (pro tlačítko ve formulářích). */
    public function __invoke(string $ico, AresClient $ares): JsonResponse
    {
        $result = $ares->lookup($ico);

        if ($result === null) {
            return response()->json([
                'message' => 'Subjekt s tímto IČO se v ARES nepodařilo najít.',
            ], 404);
        }

        return response()->json($result);
    }
}
