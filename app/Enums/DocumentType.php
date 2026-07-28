<?php

namespace App\Enums;

enum DocumentType: string
{
    case Invoice = 'invoice';
    case Proforma = 'proforma';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Faktura',
            self::Proforma => 'Proforma',
            self::CreditNote => 'Opravný daňový doklad',
        };
    }

    /** Název dokladu na PDF — stejný pro plátce i neplátce DPH. */
    public function documentTitle(): string
    {
        return match ($this) {
            self::Invoice => 'Faktura - Daňový doklad',
            self::Proforma => 'Proforma faktura',
            self::CreditNote => 'Opravný daňový doklad',
        };
    }
}
