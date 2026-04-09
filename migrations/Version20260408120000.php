<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la persistance des statuts de liste de courses.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof SqlitePlatform) {
            $this->addSql('CREATE TABLE shopping_list_item_state (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, period_start DATETIME NOT NULL, period_end DATETIME NOT NULL, ingredient_key VARCHAR(180) NOT NULL, unit_key VARCHAR(80) NOT NULL, status VARCHAR(16) NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_shopping_state_period_item ON shopping_list_item_state (period_start, period_end, ingredient_key, unit_key)');

            return;
        }

        if ($platform instanceof MySQLPlatform) {
            $this->addSql('CREATE TABLE shopping_list_item_state (id INT AUTO_INCREMENT NOT NULL, period_start DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', period_end DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ingredient_key VARCHAR(180) NOT NULL, unit_key VARCHAR(80) NOT NULL, status VARCHAR(16) NOT NULL, UNIQUE INDEX uniq_shopping_state_period_item (period_start, period_end, ingredient_key, unit_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform "%s" for migration %s', $platform::class, self::class));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shopping_list_item_state');
    }
}
