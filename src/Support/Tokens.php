<?php

namespace Ginkelsoft\EncryptedSearch\Support;

/**
 * Class Tokens
 *
 * Provides deterministic and privacy-preserving token generation
 * for use in encrypted search indexes.
 *
 * Each token is produced using a one-way SHA-256 hash, optionally
 * combined with a secret "pepper" value from configuration to make
 * correlation or dictionary attacks significantly harder.
 *
 * There are two token types:
 *
 * - **Exact tokens**: deterministic hashes of the fully normalized field value.
 *   Used for exact equality lookups (e.g., "last_names = 'vermeer'").
 *
 * - **Prefix tokens**: deterministic hashes of all prefixes of a normalized
 *   string (e.g., "v", "ve", "ver", ...). Used to enable fast, secure
 *   "starts with" queries without exposing plaintext or raw prefixes.
 *
 * All outputs are SHA-256 hexadecimal hashes, ensuring fixed-length,
 * index-friendly values suitable for database indexing.
 *
 * Example:
 * ```php
 * Tokens::exact('wietsevanginkel', 'pepper');
 * Tokens::prefixes('wietsevanginkel', 3, 'pepper');
 * ```
 */
class Tokens
{
    /**
     * Generate an exact-match token for a normalized string.
     *
     * This produces a deterministic SHA-256 hex hash of the normalized value
     * concatenated with a secret pepper. The pepper (defined in config) prevents
     * offline hash table attacks if the token database is compromised.
     *
     * @param string $normalized
     *     The normalized (preprocessed) string.
     * @param string $pepper
     *     A secret, application-level random string from configuration.
     *
     * @return string
     *     Hex-encoded SHA-256 hash (64 characters).
     *
     * @throws \RuntimeException if pepper is empty
     */
    public static function exact(string $normalized, string $pepper, string $context = ''): string
    {
        if (empty($pepper)) {
            throw new \RuntimeException(
                'SEARCH_PEPPER is not configured. Set it in your .env file for security. ' .
                'Generate a random string: openssl rand -base64 32'
            );
        }

        $input = $context !== '' ? $context . '|' . $normalized : $normalized;

        return hash('sha256', $input . $pepper);
    }

    /**
     * Generate multiple prefix tokens for a normalized string.
     *
     * Each prefix of the input string (up to `$maxDepth` characters)
     * is hashed independently using SHA-256 with the same pepper.
     * These prefix hashes can be used to implement fast "starts-with"
     * queries while maintaining cryptographic privacy.
     *
     * Only prefixes at or above the minimum length (from config) are generated.
     * This prevents overly broad matches from very short search terms.
     *
     * Example: "alex" with maxDepth=4, minLength=2 yields tokens for "al", "ale", "alex".
     * (skips "a" because it's below minimum length)
     *
     * @param string $normalized
     *     The normalized (lowercase, diacritic-free) string.
     * @param int $maxDepth
     *     The maximum number of prefix characters to hash.
     * @param string $pepper
     *     A secret application-level random string from configuration.
     * @param int $minLength
     *     The minimum prefix length to generate (default: 1 for backwards compatibility).
     *
     * @return string[]
     *     An array of hex-encoded SHA-256 prefix tokens.
     *
     * @throws \RuntimeException if pepper is empty
     */
    public static function prefixes(string $normalized, int $maxDepth, string $pepper, int $minLength = 1, string $context = ''): array
    {
        if (empty($pepper)) {
            throw new \RuntimeException(
                'SEARCH_PEPPER is not configured. Set it in your .env file for security. ' .
                'Generate a random string: openssl rand -base64 32'
            );
        }

        $out = [];
        $len = mb_strlen($normalized, 'UTF-8');
        $depth = min($maxDepth, $len);

        // Start from minimum length instead of 1
        $start = max(1, $minLength);

        for ($i = $start; $i <= $depth; $i++) {
            $prefix = mb_substr($normalized, 0, $i, 'UTF-8');
            $input = $context !== '' ? $context . '|' . $prefix : $prefix;
            $out[] = hash('sha256', $input . $pepper);
        }

        return $out;
    }
}
