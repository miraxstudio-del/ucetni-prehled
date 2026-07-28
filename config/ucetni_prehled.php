<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hlavní šifrovací klíč dat (KEK)
    |--------------------------------------------------------------------------
    | Šifruje datové klíče organizací, kterými jsou zašifrována citlivá pole
    | v databázi. Záměrně oddělený od APP_KEY, aby šlo jeden rotovat bez
    | rozbití druhého.
    |
    | POZOR: bez tohoto klíče NELZE data dešifrovat. Zálohujte ho mimo server.
    | Vygenerování: php artisan ucetni-prehled:key-generate
    */
    'encryption_key' => env('UCETNI_PREHLED_ENCRYPTION_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Kontrola uniklých hesel (HIBP k-anonymity API)
    |--------------------------------------------------------------------------
    */
    'hibp_enabled' => env('HIBP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Externí API
    |--------------------------------------------------------------------------
    */
    'ares' => [
        'base_url' => env('ARES_BASE_URL', 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest'),
    ],

    'fio' => [
        'base_url' => env('FIO_API_BASE_URL', 'https://fioapi.fio.cz/v1/rest'),
    ],

];
