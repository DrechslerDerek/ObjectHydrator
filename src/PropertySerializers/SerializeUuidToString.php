<?php

declare(strict_types=1);

namespace EventSauce\ObjectHydrator\PropertySerializers;

use Attribute;
use EventSauce\ObjectHydrator\ObjectHydrator;
use EventSauce\ObjectHydrator\PropertySerializer;
use Ramsey\Uuid\UuidInterface;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class SerializeUuidToString implements PropertySerializer
{
    public function serialize(mixed $value, ObjectHydrator $hydrator): mixed
    {
        return $value instanceof UuidInterface
            ? $value->toString()
            : $value;
    }
}
