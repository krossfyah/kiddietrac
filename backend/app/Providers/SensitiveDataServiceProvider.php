<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\SensitiveMySqlConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

/**
 * Field-level encryption of children's health information (App\Support\Sensitive).
 * Registered FIRST in bootstrap/providers.php so the connection class is in place before
 * anything opens a database connection.
 */
class SensitiveDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('mysql', function ($connection, $database, $prefix, $config) {
            return new SensitiveMySqlConnection($connection, $database, $prefix, $config);
        });
    }
}
