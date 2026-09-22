<?php

namespace App\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class AuthRateLimitSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $connection = $event->getEntityManager()->getConnection();
        $cache = new DoctrineDbalAdapter($connection, 'auth', 0, ['db_table' => 'auth_rate_limits']);
        $cache->configureSchema($event->getSchema(), $connection, static fn () => true);
    }
}
