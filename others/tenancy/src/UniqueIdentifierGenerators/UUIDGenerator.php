<?php

declare(strict_types=1);

namespace Stancl\Tenancy\UniqueIdentifierGenerators;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Generates a UUIDv4 for the tenant key.
 */
class UUIDGenerator implements UniqueIdentifierGenerator
{
    public static function generate(Model $model): string|int
    {
        return Uuid::uuid4()->toString();
    }
}
