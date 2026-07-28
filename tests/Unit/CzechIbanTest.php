<?php

namespace Tests\Unit;

use App\Services\Invoicing\CzechIban;
use PHPUnit\Framework\TestCase;

class CzechIbanTest extends TestCase
{
    public function test_generates_valid_iban(): void
    {
        $iban = CzechIban::fromNational(null, '2501234567', '2010');

        $this->assertSame(24, strlen($iban));
        $this->assertStringStartsWith('CZ', $iban);
        $this->assertStringContainsString('2010', $iban);
        $this->assertTrue($this->isValidIban($iban), "IBAN {$iban} neprošel mod-97 kontrolou");
    }

    public function test_generates_valid_iban_with_prefix(): void
    {
        $iban = CzechIban::fromNational('123', '1234567890', '0100');

        $this->assertTrue($this->isValidIban($iban));
        $this->assertStringContainsString('000123', $iban);
    }

    public function test_parses_national_format(): void
    {
        $this->assertSame(
            ['prefix' => '123', 'number' => '1234567890', 'bank_code' => '0100'],
            CzechIban::parseNational('123-1234567890/0100'),
        );

        $this->assertSame(
            ['prefix' => null, 'number' => '2501234567', 'bank_code' => '2010'],
            CzechIban::parseNational('2501234567/2010'),
        );

        $this->assertNull(CzechIban::parseNational('neplatny-vstup'));
        $this->assertNull(CzechIban::parseNational('123/12'));
    }

    /** Standardní ISO 13616 kontrola: přesun prvních 4 znaků na konec, převod písmen, mod 97 == 1. */
    private function isValidIban(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        return bcmod($numeric, '97') === '1';
    }
}
