<?php

namespace Ginkelsoft\EncryptedSearch\Support;

class Tokens
{
    public static function exact(string $normalized, string $pepper): string
    {
        // sha256 hex
        return hash('sha256', $normalized . $pepper);
    }

    public static function prefixes(string $normalized, int $maxDepth, string $pepper): array
    {
        $out = [];
        $len = mb_strlen($normalized, 'UTF-8');
        $depth = min($maxDepth, $len);

        for ($i = 1; $i <= $depth; $i++) {
            $prefix = mb_substr($normalized, 0, $i, 'UTF-8');
            $out[] = hash('sha256', $prefix . $pepper);
        }
        return $out;
    }
}
