<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le champ steps sur recipe.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE recipe ADD steps LONGTEXT DEFAULT NULL');

            return;
        }

        if ($platform instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE recipe ADD COLUMN steps CLOB DEFAULT NULL');

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform "%s" for migration %s', $platform::class, self::class));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP COLUMN steps');
    }
}
