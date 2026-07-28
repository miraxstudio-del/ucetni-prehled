<?php

namespace Tests\Unit;

use App\Services\Bank\GpcParser;
use PHPUnit\Framework\TestCase;

class GpcParserTest extends TestCase
{
    private GpcParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GpcParser;
    }

    public function test_parses_header_and_credit_item(): void
    {
        $content = $this->headerLine('2501234567')."\r\n"
            .$this->itemLine(
                counterparty: '1234567890',
                docNumber: '13',
                halere: 170000,       // 1 700,00 Kč
                code: '2',            // kredit
                vs: '20260001',
                bankCode: '0800',
                ks: '308',
                valuta: '150726',
                name: 'Novak Jan',
            );

        $result = $this->parser->parse($content);

        $this->assertSame('2501234567', $result['account_number']);
        $this->assertCount(1, $result['transactions']);

        $transaction = $result['transactions'][0];
        $this->assertSame('1700.00', $transaction->amount);
        $this->assertSame('2026-07-15', $transaction->bookedOn);
        $this->assertSame('20260001', $transaction->variableSymbol);
        $this->assertSame('1234567890/0800', $transaction->counterpartyAccount);
        $this->assertSame('308', $transaction->constantSymbol);
        $this->assertSame('Novak Jan', $transaction->counterpartyName);
        $this->assertStringStartsWith('gpc-', $transaction->externalId);
    }

    public function test_debit_and_storno_signs(): void
    {
        $content = implode("\r\n", [
            $this->itemLine(halere: 50000, code: '1', valuta: '010726'),  // debet → záporná
            $this->itemLine(halere: 30000, code: '2', valuta: '020726'),  // kredit → kladná
            $this->itemLine(halere: 20000, code: '5', valuta: '030726'),  // storno kreditu → záporná
        ]);

        $amounts = array_map(
            fn ($t) => $t->amount,
            $this->parser->parse($content)['transactions'],
        );

        $this->assertSame(['-500.00', '300.00', '-200.00'], $amounts);
    }

    public function test_converts_cp1250_encoding(): void
    {
        $line = $this->itemLine(name: 'Šťastný Jiří', valuta: '150726');
        $cp1250 = iconv('UTF-8', 'CP1250', $line);

        $result = $this->parser->parse($cp1250);

        $this->assertSame('Šťastný Jiří', $result['transactions'][0]->counterpartyName);
    }

    public function test_same_line_produces_same_external_id(): void
    {
        $line = $this->itemLine(valuta: '150726');

        $first = $this->parser->parse($line)['transactions'][0];
        $second = $this->parser->parse($line)['transactions'][0];

        $this->assertSame($first->externalId, $second->externalId);
    }

    public function test_ignores_garbage_lines(): void
    {
        $result = $this->parser->parse("nesmysl\r\n\r\n074kratka");

        $this->assertSame([], $result['transactions']);
    }

    private function headerLine(string $account): string
    {
        return '074'
            .str_pad($account, 16, '0', STR_PAD_LEFT)
            .str_pad('DEMO FIRMA', 20)
            .'140726'
            .str_pad('0', 14, '0', STR_PAD_LEFT).'+'
            .str_pad('0', 14, '0', STR_PAD_LEFT).'+'
            .str_pad('0', 14, '0', STR_PAD_LEFT).'+'
            .str_pad('0', 14, '0', STR_PAD_LEFT).'+'
            .'001'
            .'150726';
    }

    private function itemLine(
        string $counterparty = '1234567890',
        string $docNumber = '1',
        int $halere = 100000,
        string $code = '2',
        string $vs = '123',
        string $bankCode = '0100',
        string $ks = '0',
        string $ss = '0',
        string $valuta = '150726',
        string $name = 'PROTISTRANA',
    ): string {
        return '075'
            .str_pad('2501234567', 16, '0', STR_PAD_LEFT)
            .str_pad($counterparty, 16, '0', STR_PAD_LEFT)
            .str_pad($docNumber, 13, '0', STR_PAD_LEFT)
            .str_pad((string) $halere, 12, '0', STR_PAD_LEFT)
            .$code
            .str_pad($vs, 10, '0', STR_PAD_LEFT)
            .str_pad($bankCode, 4, '0', STR_PAD_LEFT)
            .str_pad($ks, 4, '0', STR_PAD_LEFT)
            .str_pad($ss, 10, '0', STR_PAD_LEFT)
            .$valuta
            .str_pad(mb_substr($name, 0, 20), 20);
    }
}
