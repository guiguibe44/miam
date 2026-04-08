<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la persistance des statuts de liste de courses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shopping_list_item_state (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, period_start DATETIME NOT NULL, period_end DATETIME NOT NULL, ingredient_key VARCHAR(180) NOT NULL, unit_key VARCHAR(80) NOT NULL, status VARCHAR(16) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_shopping_state_period_item ON shopping_list_item_state (period_start, period_end, ingredient_key, unit_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shopping_list_item_state');
    }
}
