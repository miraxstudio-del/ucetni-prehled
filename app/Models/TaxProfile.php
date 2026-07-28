<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EncryptsAttributes;
use App\Services\Tax\TaxInput;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxProfile extends Model
{
    use BelongsToOrganization, EncryptsAttributes, HasFactory;

    /** Poznámka poplatníka může nést citlivé údaje. */
    protected $encrypted = ['note'];

    /**
     * Zrcadlí výchozí hodnoty z migrace. Bez toho má čerstvě založený profil
     * null tam, kde databáze slibuje default — firstOrCreate si vloženou
     * řádku zpátky nenačítá.
     */
    protected $attributes = [
        'secondary_activity' => true,
        'state_health_payer' => true,
        'pausal_percent' => 60,
        'actual_expenses' => 0,
        'children' => 0,
        'claim_taxpayer_credit' => true,
        'claim_children' => true,
        'claim_spouse_credit' => false,
        'social_advances_paid' => 0,
        'health_advances_paid' => 0,
    ];

    protected $fillable = [
        'organization_id',
        'year',
        'secondary_activity',
        'secondary_activity_until',
        'state_health_payer',
        'pausal_percent',
        'actual_expenses',
        'children',
        'claim_taxpayer_credit',
        'claim_children',
        'claim_spouse_credit',
        'social_advances_paid',
        'health_advances_paid',
        'income_override',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'secondary_activity' => 'boolean',
            'secondary_activity_until' => 'date',
            'state_health_payer' => 'boolean',
            'pausal_percent' => 'integer',
            'actual_expenses' => 'decimal:2',
            'children' => 'integer',
            'claim_taxpayer_credit' => 'boolean',
            'claim_children' => 'boolean',
            'claim_spouse_credit' => 'boolean',
            'social_advances_paid' => 'decimal:2',
            'health_advances_paid' => 'decimal:2',
            'income_override' => 'decimal:2',
        ];
    }

    /** Sestaví vstup pro kalkulačku. Příjem se předává zvenčí — z faktur nebo z přiznání. */
    public function toInput(int $income, array $changes = []): TaxInput
    {
        // Datum přechodu vedlejší → hlavní platí, jen když spadá do
        // vykazovaného roku — jinak (jiný rok, prázdné) se řídí celý rok
        // jen příznakem secondary_activity jako dřív.
        $secondaryMonths = $this->secondary_activity_until !== null
            && (int) $this->secondary_activity_until->year === $this->year
            ? $this->secondary_activity_until->month
            : null;

        return (new TaxInput(
            year: $this->year,
            income: $income,
            pausalPercent: $this->pausal_percent,
            actualExpenses: (int) round((float) $this->actual_expenses),
            // datum přechodu znamená, že aspoň část roku vedlejší byla
            secondaryActivity: $this->secondary_activity || $secondaryMonths !== null,
            secondaryMonths: $secondaryMonths,
            stateHealthPayer: $this->state_health_payer,
            children: $this->children,
            claimTaxpayerCredit: $this->claim_taxpayer_credit,
            claimChildren: $this->claim_children,
            claimSpouseCredit: $this->claim_spouse_credit,
            socialAdvancesPaid: (int) round((float) $this->social_advances_paid),
            healthAdvancesPaid: (int) round((float) $this->health_advances_paid),
        ))->with($changes);
    }
}
