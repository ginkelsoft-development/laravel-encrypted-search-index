<?php

namespace Ginkelsoft\EncryptedSearch\Support;

class Normalizer
{
    public static function normalize(?string $v): ?string
    {
        if ($v === null) return null;

        $s = mb_strtolower($v, 'UTF-8');

        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = preg_replace('/\p{M}/u', '', $s); // strip diacritics
        }

        // enkel letters/cijfers behouden
        $s = preg_replace('/[^a-z0-9]/u', '', $s);

        return $s;
    }
}
