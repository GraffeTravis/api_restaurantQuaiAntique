<?php

declare(strict_types=1);

namespace DoctrineMigrationsProd;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transactional booking analytics snapshots and Messenger outbox, including initial import.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');
        // Symfony merges the historical and production migration paths. Both wrappers
        // may be discovered, but the projection and its sequence must be installed once.
        if ($this->connection->fetchOne("SELECT to_regclass('booking_analytics_state')") !== null) {
            return;
        }
        foreach (explode('-- statement', file_get_contents(__DIR__.'/../config/sql/booking_analytics.sql')) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Analytics versions and deletion tombstones must be preserved.');
    }
}
