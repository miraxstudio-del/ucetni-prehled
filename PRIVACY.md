# Soukromí a lokální data

Účetní přehled je lokální aplikace. Mirax Studio neprovozuje pro Účetní přehled žádný cloudový
server, účet uživatele ani telemetrii a nemá přístup k údajům uloženým v jeho
aplikaci.

## Uložení dat

Údaje o fakturách, klientech, nastavení firmy, bankovních pohybech a nahraných
souborech zůstávají v počítači uživatele. Aplikace je ukládá do lokální databáze
a lokálního úložiště v její složce. Uživatel odpovídá za své zálohy a zabezpečení
počítače.

## Přenosy mimo počítač

Účetní přehled sám žádná data automaticky nikam neodesílá. Připojení k externí službě
nastane pouze po výslovné akci uživatele:

- vyhledání firmy podle IČO odešle požadavek do systému ARES;
- synchronizace banky komunikuje s bankovním rozhraním Fio, pokud jej uživatel
  nastaví a spustí.

Tyto služby mají vlastní podmínky a zásady zpracování údajů. Mirax Studio pro
ně nevystupuje jako provozovatel ani poskytovatel cloudového úložiště Účetní přehled.

## Cookies

Účetní přehled nepoužívá analytické, měřicí ani reklamní cookies a nezapojuje
žádnou třetí stranu, která by je nastavovala. Proto v programu není a nemusí být
žádná lišta se souhlasem — ukládají se pouze cookies technicky nezbytné pro
samotný běh programu v prohlížeči:

| Cookie | K čemu je | Jak dlouho |
| --- | --- | --- |
| `ucetni-prehled-session` | Drží rozpracovaný stav obrazovky a ochranný token formulářů (CSRF). | 2 hodiny. |
| `XSRF-TOKEN` | Tentýž ochranný token pro rozhraní programu. | 2 hodiny. |
| `ucetni_prehled_asset_cache_revision` | Poznamenává, že prohlížeč už má po aktualizaci načtený platný vzhled programu. | 1 rok. |

Cookies vznikají jen v prohlížeči na počítači uživatele, nikam se neodesílají a
neobsahují jeho osobní údaje. Nastavení světlého a tmavého vzhledu si program
ukládá do místního úložiště prohlížeče (`localStorage`), nikoli do cookie.

## Odpovědnost za osobní údaje

Pokud uživatel do Účetní přehled ukládá osobní údaje svých klientů, zaměstnanců nebo
dodavatelů, rozhoduje o účelu a způsobu jejich použití on. Musí proto zajistit
zákonný titul, informování dotčených osob, bezpečnost a dobu uchování podle
pravidel, která se na jeho činnost vztahují.

Poslední aktualizace: 28. července 2026.
