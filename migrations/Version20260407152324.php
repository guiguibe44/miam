<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260407152324 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recipe ADD COLUMN image_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__recipe AS SELECT id, name, preparation_time_minutes, estimated_cost, main_ingredient, seasonality FROM recipe');
        $this->addSql('DROP TABLE recipe');
        $this->addSql('CREATE TABLE recipe (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(160) NOT NULL, preparation_time_minutes SMALLINT NOT NULL, estimated_cost NUMERIC(7, 2) NOT NULL, main_ingredient VARCHAR(80) NOT NULL, seasonality VARCHAR(16) NOT NULL)');
        $this->addSql('INSERT INTO recipe (id, name, preparation_time_minutes, estimated_cost, main_ingredient, seasonality) SELECT id, name, preparation_time_minutes, estimated_cost, main_ingredient, seasonality FROM __temp__recipe');
        $this->addSql('DROP TABLE __temp__recipe');
    }
}
