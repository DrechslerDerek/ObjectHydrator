<?php

declare(strict_types=1);

namespace EventSauce\ObjectHydrator;

class PropertyDefinition
{
    public function __construct(
        public string $key,
        public string $property,
        public ?string $propertyCaster,
        public array $castingOptions,
        public bool $canBeHydrated,
        public bool $isEnum,
        public ?string $concreteTypeName,
    )
    {
    }
}