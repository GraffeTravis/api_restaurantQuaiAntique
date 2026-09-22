<?php

declare(strict_types=1);

namespace DoctrineMigrationsProd;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;

final class Version20260921020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expire legacy sessions; store revocable token hashes and durable auth rate limits.';
    }

    public function up(Schema $schema): void
    {
        $manager = $this->connection->createSchemaManager();
        $columns = $manager->listTableColumns('user');
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform && $columns['api_token']->getNotnull()) {
            $this->addSql('ALTER TABLE '.$platform->quoteSingleIdentifier('user').' ALTER COLUMN '.$platform->quoteSingleIdentifier('api_token').' DROP NOT NULL');
        }
        if (!isset($columns['api_token_expires_at'])) {
            $declaration = Type::getType(Types::DATETIME_IMMUTABLE)->getSQLDeclaration(['notnull' => false], $platform);
            $this->addSql('ALTER TABLE '.$platform->quoteSingleIdentifier('user').' ADD '.$platform->quoteSingleIdentifier('api_token_expires_at').' '.$declaration);
        }

        $indexNames = array_map('strtolower', array_keys($manager->listTableIndexes('user')));
        if (!in_array('uniq_user_api_token', $indexNames, true)) {
            $this->addSql('CREATE UNIQUE INDEX '.$platform->quoteSingleIdentifier('uniq_user_api_token').' ON '.$platform->quoteSingleIdentifier('user').' ('.$platform->quoteSingleIdentifier('api_token').')');
        }

        $current = $manager->introspectSchema();
        $target = clone $current;
        $cache = new DoctrineDbalAdapter($this->connection, 'auth', 0, ['db_table' => 'auth_rate_limits']);
        $cache->configureSchema($target, $this->connection, static fn () => true);
        $diff = $manager->createComparator()->compareSchemas($current, $target);
        foreach ($platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('UPDATE '.$platform->quoteSingleIdentifier('user').' SET api_token = NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Legacy tokens cannot be recovered safely.');
    }
}
