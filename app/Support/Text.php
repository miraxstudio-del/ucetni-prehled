<?php

namespace App\Support;

/**
 * Pomocné textové funkce nezávislé na dostupnosti rozšíření intl
 * (sdílený hosting nemusí mít transliterator).
 */
class Text
{
    /** Odstranění diakritiky (pro SPD QR, GPC a další ASCII-only formáty). */
    public static function ascii(string $value): string
    {
        if (function_exists('transliterator_transliterate')) {
            $result = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);

            if ($result !== false) {
                return $result;
            }
        }

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $value) : $converted;
    }
}
