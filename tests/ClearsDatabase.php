<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared trait for clearing database for every test case that needs it.
 */
trait ClearsDatabase
{
    // children tables are cleared first in case some tables are not set to ON DELETE CASCADE
    protected function clearDatabase(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();

        foreach ([
            'order_status_history',
            'order_items',
            'orders',
            'inventory',
            'products',
            'categories',
            'promo_codes',
            'users',
        ] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }

        $em->clear();
    }
}
