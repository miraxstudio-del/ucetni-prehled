<?php

namespace App\Enums;

enum InvoiceTemplate: string
{
    case Klasik = 'klasik';
    case Moderni = 'moderni';
    case Minimal = 'minimal';
    case Tradicni = 'tradicni';

    public function label(): string
    {
        return match ($this) {
            self::Klasik => 'Klasik',
            self::Moderni => 'Moderní',
            self::Minimal => 'Minimalistický',
            self::Tradicni => 'Tradiční',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Klasik => 'Střídmý formální vzhled s barevnými popisky a QR platbou.',
            self::Moderni => 'Výrazné barevné bloky, zvýrazněná celková částka.',
            self::Minimal => 'Černobílý, jen tenké linky — bez ozdob.',
            self::Tradicni => 'Věrná kopie původních faktur — dvousloupcová hlavička, „Celkem k platbě“.',
        };
    }

    /** Blade šablona pro PDF i webový náhled — obě používají stejný soubor. */
    public function view(): string
    {
        return match ($this) {
            self::Klasik => 'pdf.invoices.klasik',
            self::Moderni => 'pdf.invoices.moderni',
            self::Minimal => 'pdf.invoices.minimal',
            self::Tradicni => 'pdf.invoices.tradicni',
        };
    }
}
