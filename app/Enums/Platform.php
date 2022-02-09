<?php

declare(strict_types=1);

namespace App\Enums;

use MyCLabs\Enum\Enum;

/**
 * @method static Platform POSTGRES()
 * @method static Platform MYSQL()
 *
 * @psalm-immutable
 */
final class Platform extends Enum
{
    private const POSTGRES = 'postgres';
    private const MYSQL = 'mysql';
}
