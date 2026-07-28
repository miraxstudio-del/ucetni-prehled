<?php

namespace Tests\Unit;

use App\Services\Invoicing\InvoiceCalculator;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    private InvoiceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new InvoiceCalculator;
    }

    public function test_calculates_line_and_totals_for_non_vat_payer(): void
    {
        $result = $this->calculator->calculate([
            ['description' => 'Práce', 'quantity' => '2', 'unit' => 'hod', 'unit_price' => '850', 'vat_rate' => '21'],
        ], vatPayer: false);

        // neplátce: DPH se vynuluje bez ohledu na vstup
        $this->assertSame('0.00', $result['items'][0]['vat_rate']);
        $this->assertSame('1700.00', $result['items'][0]['line_subtotal']);
        $this->assertSame('1700.00', $result['subtotal']);
        $this->assertSame('0.00', $result['vat_total']);
        $this->assertSame('1700.00', $result['total']);
    }

    public function test_calculates_vat_for_payer(): void
    {
        $result = $this->calculator->calculate([
            ['description' => 'Služba', 'quantity' => '1', 'unit' => null, 'unit_price' => '1000', 'vat_rate' => '21'],
            ['description' => 'Zboží', 'quantity' => '3', 'unit' => 'ks', 'unit_price' => '100', 'vat_rate' => '12'],
        ], vatPayer: true);

        $this->assertSame('210.00', $result['items'][0]['line_vat']);
        $this->assertSame('36.00', $result['items'][1]['line_vat']);
        $this->assertSame('1300.00', $result['subtotal']);
        $this->assertSame('246.00', $result['vat_total']);
        $this->assertSame('1546.00', $result['total']);
    }

    public function test_accepts_czech_number_format(): void
    {
        $result = $this->calculator->calculate([
            ['description' => 'Test', 'quantity' => '1,5', 'unit' => null, 'unit_price' => '1 234,50', 'vat_rate' => '0'],
        ], vatPayer: false);

        $this->assertSame('1851.75', $result['total']);
    }

    public function test_rounds_half_up_to_cents(): void
    {
        $result = $this->calculator->calculate([
            ['description' => 'Test', 'quantity' => '0.333', 'unit' => null, 'unit_price' => '100', 'vat_rate' => '21'],
        ], vatPayer: true);

        // 0.333 × 100 = 33.30; DPH 21 % = 6.993 → 6.99
        $this->assertSame('33.30', $result['items'][0]['line_subtotal']);
        $this->assertSame('6.99', $result['items'][0]['line_vat']);
    }
}
