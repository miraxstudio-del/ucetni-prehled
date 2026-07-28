<?php

namespace App\Console\Commands;

use App\Enums\DocumentType;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Import vydaných faktur z PDF (složky „Účetnictví rok XXXX“).
 *
 * Zásada: co v PDF není, se nedomýšlí. Doklad bez čitelné částky se
 * přeskočí a vypíše — radši díra v importu než vymyšlené číslo v daních.
 */
class ImportLegacyInvoicesCommand extends Command
{
    protected $signature = 'ucetni-prehled:import-legacy-invoices
        {path : Složka s PDF fakturami (prohledá se i podsložky)}
        {--organization= : ID organizace, do které se importuje}
        {--dry-run : Jen vypíše, co by se stalo}';

    protected $description = 'Naimportuje vydané faktury z PDF do evidence';

    public function handle(OrganizationContext $context): int
    {
        $path = $this->argument('path');

        if (! is_dir($path)) {
            $this->error("Složka neexistuje: {$path}");

            return self::FAILURE;
        }

        $organization = $this->organization();

        if (! $organization) {
            return self::FAILURE;
        }

        $context->forceSet($organization);

        $this->info("Organizace: {$organization->name} (#{$organization->id})");
        $this->newLine();

        $parsed = $this->parseFolder($path);

        if ($parsed === []) {
            $this->warn('Ve složce nejsou žádné faktury.');

            return self::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;
        $fixed = 0;
        $addressFixed = 0;
        $problems = [];
        $withoutCustomer = [];

        foreach ($parsed as $row) {
            if ($row['number'] === null || $row['total'] === null || $row['issued'] === null) {
                $problems[] = sprintf('%s — chybí %s', $row['file'], implode(', ', array_keys(array_filter([
                    'číslo' => $row['number'] === null,
                    'částka' => $row['total'] === null,
                    'datum vystavení' => $row['issued'] === null,
                ]))));

                continue;
            }

            $existing = Invoice::where('number', $row['number'])->first();

            if ($existing) {
                // Faktura už v evidenci je, ale minule se u ní odběratel
                // nenašel (starší, méně spolehlivá extrakce). Když ho teď
                // umíme přečíst a záznam je pořád bez klienta, dohledá se.
                if ($existing->client_id === null && $row['customer'] !== null) {
                    if ($this->option('dry-run')) {
                        $this->line(sprintf('  ~ %s  doplnil by se odběratel: %s', $row['number'], $row['customer']));
                    } else {
                        $client = $this->resolveClient($organization, $row);

                        if ($client) {
                            $existing->update(['client_id' => $client->id]);
                        }
                    }

                    $fixed++;
                } elseif ($existing->client_id !== null) {
                    $addressFixed += $this->repairClient($existing->client, $row);
                }

                $skipped++;

                continue;
            }

            if ($row['customer'] === null) {
                $withoutCustomer[] = $row['number'].($row['customerIco'] ? ' (IČO '.$row['customerIco'].')' : '');
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  + %s  %s  %10s Kč  %s',
                    $row['number'], $row['issued'], number_format($row['total'], 2, ',', ' '), $row['customer'] ?? '—'));
                $imported++;

                continue;
            }

            try {
                DB::transaction(fn () => $this->store($organization, $row));
                $imported++;
            } catch (Throwable $e) {
                $problems[] = $row['file'].' — '.$e->getMessage();
            }
        }

        $this->newLine();
        $this->info(sprintf('%s: %d faktur, přeskočeno (už v evidenci): %d',
            $this->option('dry-run') ? 'K importu' : 'Naimportováno', $imported, $skipped));

        if ($fixed > 0) {
            $this->info(sprintf('%s odběratel u %d už dřív naimportovaných faktur.',
                $this->option('dry-run') ? 'Doplnil by se' : 'Doplněn', $fixed));
        }

        if ($addressFixed > 0) {
            $this->info(sprintf('%s údaje u %d už napojených klientů (adresa/IČO/DIČ/oprava jména).',
                $this->option('dry-run') ? 'Doplnily by se' : 'Doplněny', $addressFixed));
        }

        if ($withoutCustomer !== []) {
            $this->newLine();
            $this->warn(sprintf('Faktury bez jména odběratele (%d) — v PDF chybí, doplň ručně:', count($withoutCustomer)));
            $this->line('  '.implode(', ', $withoutCustomer));
        }

        if ($problems !== []) {
            $this->newLine();
            $this->warn('Doklady, které se NEnaimportovaly — projdi je ručně:');
            foreach ($problems as $problem) {
                $this->line('  • '.$problem);
            }
        }

        return self::SUCCESS;
    }

    private function organization(): ?Organization
    {
        $id = $this->option('organization');

        if ($id) {
            $organization = Organization::withoutGlobalScopes()->find($id);

            if (! $organization) {
                $this->error("Organizace #{$id} neexistuje.");

                return null;
            }

            return $organization;
        }

        $organizations = Organization::withoutGlobalScopes()->get();

        if ($organizations->count() === 1) {
            return $organizations->first();
        }

        $this->error('Uveď --organization= — v databázi je jich víc:');
        foreach ($organizations as $organization) {
            $this->line("  #{$organization->id} {$organization->name}");
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function parseFolder(string $path): array
    {
        $parser = new Parser;
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if (! $file->isDir() && strtolower($file->getExtension()) === 'pdf') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $rows = [];
        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $file) {
            try {
                $rows[] = $this->parse($parser, $file);
            } catch (Throwable $e) {
                // Nečitelné PDF se nepřeskakuje tiše — vypadne níž mezi problémy.
                $rows[] = [
                    'file' => basename($file).' (nelze přečíst: '.$e->getMessage().')',
                    'number' => null, 'total' => null, 'issued' => null, 'customer' => null,
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $rows;
    }

    private function parse(Parser $parser, string $path): array
    {
        $name = basename($path);
        $document = $parser->parseFile($path);
        $text = preg_replace('/[ \t]+/u', ' ', $document->getText());

        preg_match('/Faktura - Daňový doklad\s*(\d{7})/u', $text, $m);
        $number = $m[1] ?? (preg_match('/(20\d{5})/', $name, $mm) ? $mm[1] : null);

        preg_match('/Datum vystavení:\s*(\d{2}\.\d{2}\.\d{4})/u', $text, $m);
        $issued = $m[1] ?? null;
        preg_match('/Datum splatnosti:\s*(\d{2}\.\d{2}\.\d{4})/u', $text, $m);
        $due = $m[1] ?? null;
        preg_match('/Datum uskutečnění zdanitelného plnění:\s*(\d{2}\.\d{2}\.\d{4})/u', $text, $m);
        $duzp = $m[1] ?? null;

        // „Celkem k platbě“ je závazné; jinak poslední částka na dokladu.
        // Vzor je schválně přísný — volnější zápis pohltil i číslo účtu.
        $total = null;
        if (preg_match('/Celkem k platbě:\s*\n?\s*(\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2})/u', $text, $m)) {
            $total = $this->toFloat($m[1]);
        } elseif (preg_match_all('/(?<![\d])(\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2})\s*Kč/u', $text, $all)) {
            $amounts = $all[1];
            $total = $this->toFloat((string) end($amounts));
        }

        $blockLines = $this->odberatelBlockLines($document);
        $customer = $blockLines !== [] ? $blockLines[0] : $this->customerNameByText($text);
        [$customerStreet, $customerZip, $customerCity] = $this->customerAddress($blockLines);

        preg_match('/IČO odběratele:\s*(\d{6,10})/u', $text, $m);
        $customerIco = $m[1] ?? null;

        preg_match('/DIČ odběratele:\s*(CZ\d{8,10})/u', $text, $m);
        $customerDic = $m[1] ?? null;

        // Některé faktury mají vyplněné jen DIČ. U právnických osob je DIČ
        // „CZ“ + IČO, takže z CZ + 8 číslic jde IČO odvodit. Delší DIČ
        // (rodné číslo fyzické osoby) se takhle použít nesmí.
        if ($customerIco === null && $customerDic !== null && preg_match('/^CZ(\d{8})$/', $customerDic, $m)) {
            $customerIco = $m[1];
        }

        return [
            'file' => $name,
            'number' => $number,
            'issued' => $this->toDate($issued),
            'duzp' => $this->toDate($duzp) ?? $this->toDate($issued),
            'due' => $this->toDate($due),
            'total' => $total,
            'customer' => $customer,
            'customerIco' => $customerIco,
            'customerDic' => $customerDic,
            'customerStreet' => $customerStreet,
            'customerZip' => $customerZip,
            'customerCity' => $customerCity,
            'blockLines' => $blockLines,
            'cancelled' => (bool) preg_match('/zrušen/iu', $name),
        ];
    }

    /**
     * Jméno a adresa odběratele z dvousloupcové faktury.
     *
     * Faktura má dva sloupce vedle sebe (dodavatel vlevo, odběratel vpravo).
     * Prostý getText() oba sloupce slévá do společných řádků podle výšky
     * řádku, ne podle logického pořadí — jméno odběratele tak může vyjít
     * uprostřed řádku dodavatele, nebo (jak se ukázalo) úplně mimo hlavičku,
     * vytištěné až za razítkem na konci dokladu. Hádat ho z takto slitého
     * textu je nespolehlivé.
     *
     * Spolehlivé je číst podle souřadnic: PDF nese u každého textu i jeho
     * pozici na stránce (Tm matice), takže se dá najít popisek „Odběratel:“
     * a vzít text pod ním ve stejném sloupci. Ověřeno na 89 fakturách: první
     * řádek je vždy jméno, u 86 z 89 pak následuje ulice a PSČ + město —
     * stejným způsobem, jakým se dřív četlo jen jméno.
     */
    private function odberatelBlockLines(Document $document): array
    {
        $page = $document->getPages()[0] ?? null;

        if (! $page || ! method_exists($page, 'getDataTm')) {
            return [];
        }

        $items = [];
        foreach ($page->getDataTm() as [$tm, $chunk]) {
            $chunk = trim((string) $chunk);

            if ($chunk !== '') {
                // Tm matice: [a, b, c, d, x, y] — pozice je (x, y).
                $items[] = ['y' => (float) $tm[5], 'x' => (float) $tm[4], 'text' => $chunk];
            }
        }

        $label = null;
        foreach ($items as $item) {
            if ($item['text'] === 'Odběratel:') {
                $label = $item;

                break;
            }
        }

        if ($label === null) {
            return [];
        }

        // Stejný sloupec (podobné x), okno ~4 řádky pod popiskem (menší y,
        // PDF souřadnice rostou nahoru) — jméno, ulice, PSČ + město.
        // Jednopismenné chunky nejsou řádky, ale diakritika vypsaná mimo
        // text (viz mergeOrphanLetters) — jako řádek by se přilepily k adrese.
        $candidates = array_values(array_filter(
            $items,
            fn ($item) => $item['x'] >= $label['x'] - 15
                && $item['x'] <= $label['x'] + 60
                && $item['y'] < $label['y']
                && $item['y'] > $label['y'] - 90
                && ! (mb_strlen($item['text']) === 1 && preg_match('/\p{L}/u', $item['text']))
        ));

        usort($candidates, fn ($a, $b) => $b['y'] <=> $a['y']);

        return array_map(
            fn ($item) => $this->mergeOrphanLetters($item, $items),
            $candidates,
        );
    }

    /**
     * Složení diakritiky vypsané mimo text.
     *
     * Některé PDF generátory tisknou háčkované písmeno jako samostatný znak
     * s vlastní pozicí — v řádku pak zbyde mezera („Havlí kova“ + osamocené
     * „č“ jinde na stejném řádku). Znak se vrátí na místo jen tehdy, když je
     * v řádku právě jedna kandidátní mezera (písmeno před ní, malé písmeno
     * za ní — skutečné mezislovní mezery mají za sebou velké písmeno nebo
     * číslici); při více kandidátech rozhodne odhad pozice z X souřadnice.
     * Nic se nedomýšlí — vkládá se pouze znak, který v PDF skutečně je.
     */
    private function mergeOrphanLetters(array $line, array $allItems): string
    {
        $text = $line['text'];

        $orphans = array_filter(
            $allItems,
            fn ($item) => abs($item['y'] - $line['y']) < 1
                && $item['x'] > $line['x']
                && mb_strlen($item['text']) === 1
                && preg_match('/\p{L}/u', $item['text'])
        );

        usort($orphans, fn ($a, $b) => $a['x'] <=> $b['x']);

        foreach ($orphans as $orphan) {
            $gaps = [];
            $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

            foreach ($chars as $i => $char) {
                if ($char === ' '
                    && isset($chars[$i - 1], $chars[$i + 1])
                    && preg_match('/\p{L}/u', $chars[$i - 1])
                    && preg_match('/\p{Ll}/u', $chars[$i + 1])) {
                    $gaps[] = $i;
                }
            }

            if ($gaps === []) {
                continue;
            }

            // průměrná šířka znaku ~5 pt při 9,5pt písmu faktury
            $estimated = (int) round(($orphan['x'] - $line['x']) / 5);
            usort($gaps, fn ($a, $b) => abs($a - $estimated) <=> abs($b - $estimated));

            $chars[$gaps[0]] = $orphan['text'];
            $text = implode('', $chars);
        }

        return $text;
    }

    /**
     * Ulice a PSČ + město z řádků pod jménem odběratele. Pořadí "PSČ Město"
     * i "Město PSČ" se v podkladech oboje objevuje, proto se PSČ hledá jako
     * vzor 3+2 číslice (s mezerou i bez) a zbytek řádku bez něj je město.
     *
     * Ulice nemusí obsahovat číslo („Důl Max“ je místní název bez č. p.) —
     * ulicí je každý řádek před řádkem s PSČ, který není telefon. Telefonní
     * řádky se přeskakují úplně: „Mob: 733 628 322“ by jinak prošel i vzorem
     * PSČ a vyrobil nesmyslnou adresu.
     *
     * @param  list<string>  $lines
     * @return array{0: ?string, 1: ?string, 2: ?string} ulice, PSČ, město
     */
    private function customerAddress(array $lines): array
    {
        $rest = array_slice($lines, 1);
        $street = null;

        foreach ($rest as $line) {
            if (preg_match('/^(Mob|Mobil|Tel|Telefon|E-?mail|www)\b/iu', $line)) {
                continue;
            }

            if (preg_match('/(\d{3})\s?(\d{2})/u', $line, $m)) {
                $zip = $m[1].' '.$m[2];
                $city = trim(str_replace($m[0], '', $line));
                $city = trim(preg_replace('/,?\s*(Česká republika|ČR|CZ)\s*$/iu', '', $city));
                $city = trim($city, " ,\t\n\r\0\x0B") ?: null;

                return [$street, $zip, $city];
            }

            $street = $street === null ? $line : $street.', '.$line;
        }

        return [$street, null, null];
    }

    /**
     * Záložní textová heuristika, kdyby PDF nemělo použitelné souřadnice.
     * Adresa i PSČ vždy obsahují číslici, jméno ne; jedno slovo je skoro
     * jistě zbytek adresy („Slovensko“, „Praha“), ne jméno ani firma.
     */
    private function customerNameByText(string $text): ?string
    {
        $left = '/^(?:[ \t]*(?:Mobil:[ \t]*\+?[\d\s]{6,}|www:|E-mail:[ \t]*\S*|IČO:[ \t]*\d*'
            .'|DIČ:[ \t]*NEPLÁTCE DPH|DIČ:[ \t]*\S*|IČO odběratele:[ \t]*\d*|DIČ odběratele:[ \t]*\S*'
            .'|Způsob platby:[ \t]*\S+(?:[ \t]+převod)?|Datum [^:]*:[ \t]*\d{2}\.\d{2}\.\d{4}'
            .'|Konečný příjemce:))+/u';

        $lines = preg_split('/\n/u', $text) ?: [];
        $started = false;

        foreach ($lines as $line) {
            if (! $started) {
                $started = str_contains($line, 'Odběratel:');

                continue;
            }

            if (str_contains($line, 'Označení dodávky')) {
                break;
            }

            $rest = trim((string) preg_replace($left, '', $line));

            if ($rest === '' || preg_match('/\d/u', $rest)) {
                continue;
            }

            if (count(preg_split('/\s+/u', $rest) ?: []) < 2) {
                continue;
            }

            return $rest;
        }

        return null;
    }

    /** Jméno i IČO klienta jsou šifrované — dohledávají se přes slepý index. */
    private function resolveClient(Organization $organization, array $row): ?Client
    {
        $client = null;

        if ($row['customerIco']) {
            $client = Client::whereIco($row['customerIco'])->first();
        }

        if (! $client && $row['customer']) {
            $client = Client::whereName($row['customer'])->first();
        }

        if (! $client && $row['customer']) {
            $client = Client::create(array_filter([
                'organization_id' => $organization->id,
                'name' => $row['customer'],
                'ico' => $row['customerIco'],
                'dic' => $row['customerDic'],
                'street' => $row['customerStreet'],
                'zip' => $row['customerZip'],
                'city' => $row['customerCity'],
            ]));
        } elseif ($client) {
            $this->repairClient($client, $row);
        }

        return $client;
    }

    /**
     * Doplní nebo opraví údaje u klienta, který už v evidenci je:
     *
     *  - přejmenuje ho, pokud jeho jméno je ve skutečnosti řádek ADRESY
     *    z téže faktury (starý textový parser občas vzal místo jména adresu,
     *    např. „Důl Max“ místo Rishabh Swami);
     *  - opraví ulici/město rozbité vypadlou diakritikou („Havlí kova“ →
     *    „Havlíčkova“) — pozná se tak, že uložená hodnota je nová hodnota
     *    s mezerou místo písmene;
     *  - doplní adresu, IČO a DIČ tam, kde chybí. Nikdy nepřepisuje
     *    vyplněné údaje něčím jiným.
     *
     * @return int 1, pokud se něco změnilo (kvůli počítadlu v souhrnu)
     */
    private function repairClient(?Client $client, array $row): int
    {
        if ($client === null) {
            return 0;
        }

        $changes = [];
        $notes = [];

        $addressLines = array_map('mb_strtolower', array_slice($row['blockLines'] ?? [], 1));
        $extractedName = $row['customer'];

        if ($extractedName !== null
            && mb_strtolower(trim((string) $client->name)) !== mb_strtolower($extractedName)
            && in_array(mb_strtolower(trim((string) $client->name)), $addressLines, true)) {
            $changes['name'] = $extractedName;
            $notes[] = sprintf('jméno "%s" → "%s" (byl to řádek adresy)', $client->name, $extractedName);
        }

        foreach (['street' => 'customerStreet', 'zip' => 'customerZip', 'city' => 'customerCity'] as $field => $key) {
            $fresh = $row[$key];

            if ($fresh === null) {
                continue;
            }

            if (blank($client->{$field})) {
                $changes[$field] = $fresh;
            } elseif ($this->isBrokenVariantOf((string) $client->{$field}, $fresh)) {
                $changes[$field] = $fresh;
                $notes[] = sprintf('%s "%s" → "%s" (vypadlá diakritika)', $field, $client->{$field}, $fresh);
            }
        }

        foreach (['ico' => 'customerIco', 'dic' => 'customerDic'] as $field => $key) {
            if ($row[$key] !== null && blank($client->{$field})) {
                $changes[$field] = $row[$key];
                $notes[] = sprintf('%s doplněno: %s', mb_strtoupper($field), $row[$key]);
            }
        }

        if ($changes === []) {
            return 0;
        }

        if ($this->option('dry-run')) {
            $this->line(sprintf('  ~ %s  %s: %s', $row['number'], $client->name,
                $notes !== [] ? implode('; ', $notes) : 'doplnila by se adresa '.implode(', ', array_filter([$row['customerStreet'], $row['customerZip'], $row['customerCity']]))));
        } else {
            $client->update($changes);
        }

        return 1;
    }

    /**
     * Uložená hodnota je „rozbitá varianta“ nové: stejně dlouhá a liší se
     * jen v pozicích, kde má mezeru místo písmene (vypadlá diakritika).
     */
    private function isBrokenVariantOf(string $stored, string $fresh): bool
    {
        if ($stored === $fresh) {
            return false;
        }

        $storedChars = preg_split('//u', $stored, -1, PREG_SPLIT_NO_EMPTY);
        $freshChars = preg_split('//u', $fresh, -1, PREG_SPLIT_NO_EMPTY);

        if (count($storedChars) !== count($freshChars)) {
            return false;
        }

        $differs = false;

        foreach ($storedChars as $i => $char) {
            if ($char === $freshChars[$i]) {
                continue;
            }

            if ($char !== ' ' || ! preg_match('/\p{L}/u', $freshChars[$i])) {
                return false;
            }

            $differs = true;
        }

        return $differs;
    }

    private function store(Organization $organization, array $row): void
    {
        $client = $this->resolveClient($organization, $row);

        // Uzavřené roky (validované přiznáním) se importují jako zaplacené —
        // platby šly z velké části mimo evidovaný účet (dobírky, agregované
        // výplaty Aukra) a rok je vypořádaný. BĚŽÍCÍ rok je jen „vystaveno“:
        // zaplacení musí doložit banka (ucetni-prehled:verify-payments), ne domněnka.
        $issuedYear = (int) substr((string) $row['issued'], 0, 4);
        $status = match (true) {
            (bool) $row['cancelled'] => InvoiceStatus::Cancelled,
            $issuedYear < now()->year => InvoiceStatus::Paid,
            default => InvoiceStatus::Issued,
        };

        Invoice::create([
            'organization_id' => $organization->id,
            'client_id' => $client?->id,
            'direction' => InvoiceDirection::Issued,
            'type' => DocumentType::Invoice,
            'status' => $status,
            'number' => $row['number'],
            'variable_symbol' => $row['number'],
            'issue_date' => $row['issued'],
            'duzp' => $row['duzp'],
            'due_date' => $row['due'] ?? $row['issued'],
            'payment_method' => PaymentMethod::BankTransfer,
            'currency' => 'CZK',
            'subtotal' => $row['total'],
            'vat_total' => 0,
            'total' => $row['total'],
            'note' => 'Importováno z '.$row['file'],
        ]);
    }

    private function toFloat(string $value): float
    {
        return (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $value);
    }

    private function toDate(?string $value): ?string
    {
        if (! $value || ! preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
            return null;
        }

        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
}
