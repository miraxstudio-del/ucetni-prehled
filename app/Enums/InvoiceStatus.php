<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Sent = 'sent';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Koncept',
            self::Issued => 'Vystavená',
            self::Sent => 'Odeslaná',
            self::Paid => 'Zaplacená',
            self::Cancelled => 'Stornovaná',
        };
    }

    /** Tailwind třídy pro badge. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
            self::Issued => 'bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300',
            self::Sent => 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
            self::Paid => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
            self::Cancelled => 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
