<?php

namespace Ginkelsoft\EncryptedSearch\Support;

/**
 * Class Normalizer
 *
 * Provides consistent normalization for text values before tokenization.
 *
 * The `normalize()` method ensures that semantically equivalent strings
 * produce the same deterministic token representation, regardless of casing,
 * diacritics, or spacing differences.
 *
 * Example transformations:
 *  - "Wietse van Ginkel" → "wietsevanginkel"
 *  - "Élodie" → "elodie"
 *
 * This normalization step is crucial for secure search indexing, ensuring
 * both privacy (via deterministic hashing) and predictable lookup behavior.
 *
 * Features:
 * - Lowercases all text (UTF-8 safe)
 * - Optionally removes diacritics using PHP’s Normalizer (if available)
 * - Strips all non-alphanumeric characters
 */
class Normalizer
{
    /**
     * Normalize a string for deterministic token generation.
     *
     * @param string|null $v
     *     Input value to normalize (nullable).
     *
     * @return string|null
     *     The normalized, lowercase, alphanumeric-only string.
     *     Returns null if input is null.
     */
    public static function normalize(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        // Convert to lowercase (UTF-8 safe)
        $s = mb_strtolower($v, 'UTF-8');

        // Optionally remove diacritics if intl extension is available
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = preg_replace('/\p{M}/u', '', $s); // strip diacritics
        }

        // Retain only letters and digits
        $s = preg_replace('/[^a-z0-9]/u', '', $s);

        return $s;
    }
}
