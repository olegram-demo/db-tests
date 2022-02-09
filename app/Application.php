<?php

declare(strict_types=1);

namespace App;

use App\Enums\Platform;
use Doctrine\DBAL\Connection;
use Illuminate\Container\Container;

class Application extends Container
{
    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function getDb(Platform $platform): Connection
    {
        return $this->make($platform->getValue());
    }
}
