<?php

namespace Ginkelsoft\EncryptedSearch\Attributes;

use Attribute;

/**
 * Marks a model property as searchable in the encrypted search index.
 *
 * Example:
 * #[EncryptedSearch(exact: true, prefix: true)]
 * public string $first_name;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class EncryptedSearch
{
    public function __construct(
        public bool $exact = true,
        public bool $prefix = false
    ) {}

    public function toArray(): array
    {
        return [
            'exact' => $this->exact,
            'prefix' => $this->prefix,
        ];
    }
}
