<?php

namespace Tests\Unit;

use App\Models\NumberSeries;
use PHPUnit\Framework\TestCase;

class NumberSeriesFormatTest extends TestCase
{
    public function test_formats_number_with_tokens(): void
    {
        $series = new NumberSeries(['format' => '{YYYY}-{NNNN}', 'year' => 2026]);
        $this->assertSame('2026-0001', $series->formatNumber(1));
        $this->assertSame('2026-0123', $series->formatNumber(123));

        $series = new NumberSeries(['format' => 'PF{YY}{NNN}', 'year' => 2026]);
        $this->assertSame('PF26042', $series->formatNumber(42));

        $series = new NumberSeries(['format' => '{NNNNNN}', 'year' => 2026]);
        $this->assertSame('000007', $series->formatNumber(7));
    }
}
