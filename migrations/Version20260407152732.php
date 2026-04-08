<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260407152732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recipe ADD COLUMN cook_time_minutes SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE recipe ADD COLUMN difficulty VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD COLUMN calories_per_portion INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD COLUMN source_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD COLUMN portion_price_label VARCHAR(160) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__recipe AS SELECT id, name, preparation_time_minutes, estimated_cost, main_ingredient, seasonality, image_url FROM recipe');
        $this->addSql('DROP TABLE recipe');
        $this->addSql('CREATE TABLE recipe (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(160) NOT NULL, preparation_time_minutes SMALLINT NOT NULL, estimated_cost NUMERIC(7, 2) NOT NULL, main_ingredient VARCHAR(80) NOT NULL, seasonality VARCHAR(16) NOT NULL, image_url VARCHAR(512) DEFAULT NULL)');
        $this->addSql('INSERT INTO recipe (id, name, preparation_time_minutes, estimated_cost, main_ingredient, seasonality, image_url) SELECT id, name, preparation_time_minutes, estimated_cost, main_ingredient, seasonality, image_url FROM __temp__recipe');
        $this->addSql('DROP TABLE __temp__recipe');
    }
}
