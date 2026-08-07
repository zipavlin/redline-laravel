<?php

namespace Zipavlin\Redline\Transports;

use Zipavlin\Redline\Enums\FieldType;

class Field extends Transport
{
    public function __construct(
        private readonly string $name,
        private readonly FieldType $type,
        private readonly string $label,
        private readonly string $placeholder,
        private readonly array $extra = [],
    ) {}

    public static function make(
        string $name,
        FieldType $type,
        string $label,
        string $placeholder,
        array $extra = [],
    ): array {
        return (new static($name, $type, $label, $placeholder, $extra))->toArray();
    }

    public function toArray(): array
    {
        return array_merge([
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'placeholder' => $this->placeholder,
        ], $this->extra);
    }
}
