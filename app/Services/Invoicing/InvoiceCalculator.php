<?php

namespace App\Services\Invoicing;

/**
 * Výpočty částek faktury — výhradně bcmath nad decimálními řetězci
 * (peníze nikdy nepočítáme ve floatech).
 */
class InvoiceCalculator
{
    /**
     * Spočítá řádky a souhrny z položek formuláře.
     *
     * @param  array<int, array{description: string, quantity: string, unit: ?string, unit_price: string, vat_rate: string}>  $items
     * @return array{items: array<int, array<string, string>>, subtotal: string, vat_total: string, total: string}
     */
    public function calculate(array $items, bool $vatPayer): array
    {
        $subtotal = '0.00';
        $vatTotal = '0.00';
        $result = [];

        foreach (array_values($items) as $index => $item) {
            $quantity = $this->normalize($item['quantity'] ?? '1', 3);
            $unitPrice = $this->normalize($item['unit_price'] ?? '0', 2);
            $vatRate = $vatPayer ? $this->normalize($item['vat_rate'] ?? '0', 2) : '0.00';

            $lineSubtotal = $this->round(bcmul($quantity, $unitPrice, 6), 2);
            $lineVat = $this->round(bcmul($lineSubtotal, bcdiv($vatRate, '100', 6), 6), 2);
            $lineTotal = bcadd($lineSubtotal, $lineVat, 2);

            $subtotal = bcadd($subtotal, $lineSubtotal, 2);
            $vatTotal = bcadd($vatTotal, $lineVat, 2);

            $result[] = [
                'position' => $index,
                'description' => trim((string) ($item['description'] ?? '')),
                'quantity' => $quantity,
                'unit' => ($item['unit'] ?? '') ?: null,
                'unit_price' => $unitPrice,
                'vat_rate' => $vatRate,
                'line_subtotal' => $lineSubtotal,
                'line_vat' => $lineVat,
                'line_total' => $lineTotal,
            ];
        }

        return [
            'items' => $result,
            'subtotal' => $subtotal,
            'vat_total' => $vatTotal,
            'total' => bcadd($subtotal, $vatTotal, 2),
        ];
    }

    /** Zaokrouhlení half-up nad decimálním řetězcem. */
    private function round(string $value, int $scale): string
    {
        $adjustment = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', 10) >= 0) {
            return bcadd($value, $adjustment, $scale);
        }

        return bcsub($value, $adjustment, $scale);
    }

    /** Normalizace vstupu: čárky na tečky, mezery pryč, pevná přesnost. */
    private function normalize(string $value, int $scale): string
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        if (! is_numeric($value)) {
            $value = '0';
        }

        return bcadd($value, '0', $scale);
    }
}
