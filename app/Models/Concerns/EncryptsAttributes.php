<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Services\Security\DataCipher;
use App\Services\Security\KeyVault;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Transparentní šifrování atributů datovým klíčem organizace.
 *
 * Model deklaruje:
 *   protected array $encrypted = ['name', 'email', ...];
 *   protected array $encryptedJson = ['meta'];   // hodnoty ukládané jako JSON
 *
 * Šifruje se až v okamžiku ukládání (událost `saving`), ne při zápisu atributu.
 * Důvod: Eloquent u vztahů (např. $invoice->items()->createMany()) doplní cizí
 * klíč až po naplnění atributů — dřív bychom nevěděli, které organizaci záznam
 * patří, a tedy ani jakým klíčem šifrovat.
 *
 * Do autentizovaných dat (AAD) se váže tabulka + sloupec, takže ciphertext
 * nelze přenést do jiného sloupce nebo tabulky.
 *
 * POZOR: nad šifrovaným sloupcem nefunguje SQL WHERE/LIKE/ORDER BY —
 * pro vyhledávání slouží slepé indexy (viz BlindIndex).
 */
trait EncryptsAttributes
{
    public static function bootEncryptsAttributes(): void
    {
        static::saving(fn (Model $model) => $model->encryptAttributesForSave());
    }

    public function getAttribute($key)
    {
        if (! $this->isEncryptedAttribute($key)) {
            return parent::getAttribute($key);
        }

        $raw = parent::getAttribute($key);

        if (! is_string($raw) || $raw === '') {
            return $raw;
        }

        $cipher = app(DataCipher::class);

        // Hodnota ještě nezašifrovaná (nastavená v tomto requestu, nebo
        // uložená před zavedením šifrování) — vracíme, jak je.
        if (! $cipher->isEncrypted($raw)) {
            return $this->isEncryptedJsonAttribute($key) ? json_decode($raw, true) : $raw;
        }

        $value = $this->decryptValue($key, $raw);

        return $this->isEncryptedJsonAttribute($key) && is_string($value)
            ? json_decode($value, true)
            : $value;
    }

    /** Zašifruje deklarované atributy těsně před uložením. */
    public function encryptAttributesForSave(): void
    {
        $cipher = app(DataCipher::class);

        foreach ($this->encryptedAttributes() as $key) {
            $value = $this->attributes[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            // beze změny → v atributech je pořád ciphertext z databáze
            if (is_string($value) && $cipher->isEncrypted($value)) {
                continue;
            }

            $plaintext = is_array($value) || $this->isEncryptedJsonAttribute($key)
                ? (string) json_encode($value)
                : (string) $value;

            $this->attributes[$key] = $cipher->encrypt($plaintext, $this->dataKey(), $this->cipherContext($key));
        }
    }

    /** Do polí (toArray/JSON) patří čitelná hodnota, ne ciphertext. */
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        foreach ($this->encryptedAttributes() as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $this->getAttribute($key);
            }
        }

        return $attributes;
    }

    public function encryptedAttributes(): array
    {
        return $this->encrypted ?? [];
    }

    protected function isEncryptedAttribute(string $key): bool
    {
        return in_array($key, $this->encryptedAttributes(), true);
    }

    protected function isEncryptedJsonAttribute(string $key): bool
    {
        return in_array($key, $this->encryptedJson ?? [], true);
    }

    private function decryptValue(string $key, string $raw): mixed
    {
        try {
            return app(DataCipher::class)->decrypt($raw, $this->dataKey(), $this->cipherContext($key));
        } catch (RuntimeException $e) {
            Log::error('Dešifrování selhalo: '.$e->getMessage(), [
                'model' => static::class,
                'id' => $this->getKey(),
                'attribute' => $key,
            ]);

            return null;
        }
    }

    /** Kontext do AAD — sváže ciphertext s konkrétní tabulkou a sloupcem. */
    private function cipherContext(string $key): string
    {
        return $this->getTable().'.'.$key;
    }

    /** Datový klíč organizace, které záznam patří (nebo globální klíč). */
    private function dataKey(): string
    {
        $vault = app(KeyVault::class);

        // Záznamy bez organizace (uživatelé, audit log před přihlášením)
        if ($this->usesGlobalEncryptionKey()) {
            return $vault->globalDataKey();
        }

        $organization = $this->resolveOrganizationForEncryption();

        if ($organization === null) {
            throw new RuntimeException(sprintf(
                'Nelze šifrovat %s — chybí organizace, které záznam patří.',
                static::class,
            ));
        }

        return $vault->dataKeyFor($organization);
    }

    protected function usesGlobalEncryptionKey(): bool
    {
        return (bool) ($this->encryptWithGlobalKey ?? false);
    }

    /**
     * Model může přepsat, pokud organizaci získává jinak (např. sám je
     * organizací). Výchozí: sloupec organization_id.
     *
     * Fallback na aktivní organizaci je tu záměrně: `saving` se u nového
     * záznamu spouští dřív než `creating`, ve kterém BelongsToOrganization
     * doplňuje organization_id — nechceme být závislí na pořadí traitů.
     */
    protected function resolveOrganizationForEncryption(): ?Organization
    {
        $id = $this->getAttributeFromArray('organization_id');

        if ($id !== null) {
            return Organization::withoutGlobalScopes()->find($id);
        }

        return app(OrganizationContext::class)->current();
    }
}
