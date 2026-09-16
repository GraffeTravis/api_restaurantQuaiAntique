<?php

declare(strict_types=1);

namespace DoctrineMigrationsProd;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add database-backed image storage for gallery uploads.';
    }

    public function up(Schema $schema): void
    {
        $pictureTable = $schema->getTable('picture');

        if (!$pictureTable->hasColumn('image_data')) {
            $this->addSql('ALTER TABLE picture ADD image_data TEXT DEFAULT NULL');
        }

        if (!$pictureTable->hasColumn('image_mime_type')) {
            $this->addSql('ALTER TABLE picture ADD image_mime_type VARCHAR(64) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $pictureTable = $schema->getTable('picture');

        if ($pictureTable->hasColumn('image_data')) {
            $this->addSql('ALTER TABLE picture DROP image_data');
        }

        if ($pictureTable->hasColumn('image_mime_type')) {
            $this->addSql('ALTER TABLE picture DROP image_mime_type');
        }
    }
}
