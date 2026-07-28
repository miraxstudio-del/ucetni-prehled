<?php

/*
|--------------------------------------------------------------------------
| Daňové a pojistné parametry podle roku
|--------------------------------------------------------------------------
|
| Jediné místo, kde žijí zákonné částky. U každého roku je uveden zdroj —
| bez ověřeného zdroje se sem číslo nepíše. Špatné číslo v daních je horší
| než dotaz navíc.
|
| Ověřeno zpětně proti podanému přiznání za rok 2025 (Andrea Letochová):
| základ 143 126 → daň 21 465, bonus 37 524, sociální 22 987, zdravotní 9 662.
|
*/

return [

    /*
    | Rok, který se nabídne jako výchozí, není-li zvolen jiný.
    */
    'default_year' => (int) date('Y'),

    /*
    | Uzavřené roky — příjmy se berou z podaného přiznání, ne z faktur.
    | Slouží jako referenční kontrola výpočtu.
    */
    'closed_years' => [
        2025 => [
            'income' => 357815,
            'note' => 'Součet faktur v evidenci je 358 957 Kč. Rozdíl 1 142 Kč se '
                .'nepodařilo z podkladů vysvětlit; závazné je podané přiznání.',
            'expected' => [
                'tax_base' => 143126,
                'tax_base_rounded' => 143100,
                'tax' => 21465,
                'bonus' => 37524,
                'social' => 22987,
                'health' => 9662,
            ],
        ],
    ],

    'years' => [

        2025 => [
            // — Daň z příjmů —
            'taxpayer_credit' => 30840,
            'spouse_credit' => 24840,
            // 1., 2., 3. a další dítě
            'child_credits' => [15204, 22320, 27840],
            'rate' => '15',
            'rate_high' => '23',
            'average_wage' => 46557,
            'high_rate_threshold' => 1676052,   // 36 × průměrná mzda
            'min_wage' => 20800,
            'bonus_min_income' => 124800,       // 6 × minimální mzda

            // — Sociální (důchodové) pojištění —
            'social_rate' => '29.2',
            'social_base_share' => '55',
            'social_secondary_threshold' => 111736,
            'social_min_advance_secondary' => 1496,

            // — Zdravotní pojištění —
            'health_rate' => '13.5',
            'health_base_share' => '50',

            // — Výdajové paušály: sazba => strop výdajů —
            'pausal_caps' => [80 => 1600000, 60 => 1200000, 40 => 800000, 30 => 600000],

            // Paušální daň — měsíční platba podle pásma.
            'flat_tax' => [
                1 => ['monthly' => 8716, 'income_limit' => 1000000],
                2 => ['monthly' => 16745, 'income_limit' => 1500000],
                3 => ['monthly' => 27139, 'income_limit' => 2000000],
            ],

            // DPH (§ 6 ZDPH, od 2025): obrat za kalendářní rok nad 2 mil.
            // → plátce od 1. 1. dalšího roku; nad 2 536 500 → plátce ihned.
            'vat_limit' => 2000000,
            'vat_limit_immediate' => 2536500,

            'sources' => [
                'https://www.cssz.gov.cz/zalohy-na-pojistne-na-duchodove-pojisteni',
                'https://www.vzp.cz/platci/informace/osvc/vymerovaci-zaklad-a-vypocet-pojistneho',
                'https://www.jakpodnikat.cz/socialni-pojisteni-a-nizky-zisk.php',
            ],
        ],

        2026 => [
            'taxpayer_credit' => 30840,
            'spouse_credit' => 24840,
            'child_credits' => [15204, 22320, 27840],
            'rate' => '15',
            'rate_high' => '23',
            'average_wage' => 48967,
            'high_rate_threshold' => 1762812,   // 36 × 48 967
            'min_wage' => 22400,
            'bonus_min_income' => 134400,       // 6 × 22 400

            'social_rate' => '29.2',
            'social_base_share' => '55',
            'social_secondary_threshold' => 117521,
            'social_min_advance_secondary' => 1574,

            'health_rate' => '13.5',
            'health_base_share' => '50',

            'pausal_caps' => [80 => 1600000, 60 => 1200000, 40 => 800000, 30 => 600000],

            // Paušální daň 2026 — 1. pásmo se zvedlo na 9 984 Kč
            // (daň 100 + sociální 6 578 + zdravotní 3 306), 2. a 3. beze změny.
            'flat_tax' => [
                1 => ['monthly' => 9984, 'income_limit' => 1000000],
                2 => ['monthly' => 16745, 'income_limit' => 1500000],
                3 => ['monthly' => 27139, 'income_limit' => 2000000],
            ],

            'vat_limit' => 2000000,
            'vat_limit_immediate' => 2536500,

            'sources' => [
                'https://www.cssz.gov.cz/-/prehled-nejdulezitejsich-udaju-pro-socialni-zabezpeceni-v-roce-2026',
                'https://portal.pohoda.cz/dane-ucetnictvi-mzdy/mzdy-a-prace/danove-zvyhodneni-na-deti-a-slevy-na-dani-v-roce-2026/',
                'https://www.podnikatel.cz/clanky/od-jake-vyse-mzdy-a-prijmu-se-bude-v-roce-2026-platit-23-sazba-dane-z-prijmu/',
                'https://financnisprava.gov.cz/cs/financni-sprava/media-a-verejnost/tiskove-zpravy-gfr/tiskove-zpravy-2025/pausalni-dan-2026-novinky-terminy',
            ],
        ],

    ],

];
