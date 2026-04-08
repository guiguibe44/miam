<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260407154520 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("ALTER TABLE recipe_ingredient ADD COLUMN ingredient_name VARCHAR(180) NOT NULL DEFAULT ''");
        $this->addSql('UPDATE recipe_ingredient SET ingredient_name = COALESCE((SELECT name FROM ingredient WHERE ingredient.id = recipe_ingredient.ingredient_id), ingredient_name)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__recipe_ingredient AS SELECT id, quantity, unit, recipe_id, ingredient_id FROM recipe_ingredient');
        $this->addSql('DROP TABLE recipe_ingredient');
        $this->addSql('CREATE TABLE recipe_ingredient (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity NUMERIC(8, 2) NOT NULL, unit VARCHAR(20) NOT NULL, recipe_id INTEGER NOT NULL, ingredient_id INTEGER DEFAULT NULL, CONSTRAINT FK_22D1FE1359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_22D1FE13933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO recipe_ingredient (id, quantity, unit, recipe_id, ingredient_id) SELECT id, quantity, unit, recipe_id, ingredient_id FROM __temp__recipe_ingredient');
        $this->addSql('DROP TABLE __temp__recipe_ingredient');
        $this->addSql('CREATE INDEX IDX_22D1FE1359D8A214 ON recipe_ingredient (recipe_id)');
        $this->addSql('CREATE INDEX IDX_22D1FE13933FE08C ON recipe_ingredient (ingredient_id)');
    }
}
