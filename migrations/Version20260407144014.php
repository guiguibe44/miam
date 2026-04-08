<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260407144014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ingredient (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL, default_unit VARCHAR(20) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6BAF78705E237E06 ON ingredient (name)');
        $this->addSql('CREATE TABLE meal_slot (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, served_on DATETIME NOT NULL, meal_type VARCHAR(10) NOT NULL, weekly_plan_id INTEGER NOT NULL, recipe_id INTEGER DEFAULT NULL, CONSTRAINT FK_B6D0F393E0E9AE94 FOREIGN KEY (weekly_plan_id) REFERENCES weekly_plan (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B6D0F39359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B6D0F393E0E9AE94 ON meal_slot (weekly_plan_id)');
        $this->addSql('CREATE INDEX IDX_B6D0F39359D8A214 ON meal_slot (recipe_id)');
        $this->addSql('CREATE TABLE recipe (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(160) NOT NULL, preparation_time_minutes SMALLINT NOT NULL, estimated_cost NUMERIC(7, 2) NOT NULL, main_ingredient VARCHAR(80) NOT NULL, seasonality VARCHAR(16) NOT NULL)');
        $this->addSql('CREATE TABLE recipe_ingredient (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity NUMERIC(8, 2) NOT NULL, unit VARCHAR(20) NOT NULL, recipe_id INTEGER NOT NULL, ingredient_id INTEGER NOT NULL, CONSTRAINT FK_22D1FE1359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_22D1FE13933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_22D1FE1359D8A214 ON recipe_ingredient (recipe_id)');
        $this->addSql('CREATE INDEX IDX_22D1FE13933FE08C ON recipe_ingredient (ingredient_id)');
        $this->addSql('CREATE TABLE weekly_plan (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, week_start_at DATETIME NOT NULL)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE ingredient');
        $this->addSql('DROP TABLE meal_slot');
        $this->addSql('DROP TABLE recipe');
        $this->addSql('DROP TABLE recipe_ingredient');
        $this->addSql('DROP TABLE weekly_plan');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
