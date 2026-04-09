<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260407153847 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof SqlitePlatform) {
            $this->addSql('CREATE TEMPORARY TABLE __temp__recipe_ingredient AS SELECT id, quantity, unit, recipe_id, ingredient_id FROM recipe_ingredient');
            $this->addSql('DROP TABLE recipe_ingredient');
            $this->addSql('CREATE TABLE recipe_ingredient (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity NUMERIC(8, 2) NOT NULL, unit VARCHAR(20) NOT NULL, recipe_id INTEGER NOT NULL, ingredient_id INTEGER DEFAULT NULL, CONSTRAINT FK_22D1FE1359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_22D1FE13933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('INSERT INTO recipe_ingredient (id, quantity, unit, recipe_id, ingredient_id) SELECT id, quantity, unit, recipe_id, ingredient_id FROM __temp__recipe_ingredient');
            $this->addSql('DROP TABLE __temp__recipe_ingredient');
            $this->addSql('CREATE INDEX IDX_22D1FE13933FE08C ON recipe_ingredient (ingredient_id)');
            $this->addSql('CREATE INDEX IDX_22D1FE1359D8A214 ON recipe_ingredient (recipe_id)');

            return;
        }

        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE recipe_ingredient DROP FOREIGN KEY FK_22D1FE13933FE08C');
            $this->addSql('ALTER TABLE recipe_ingredient CHANGE ingredient_id ingredient_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE13933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id)');

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform "%s" for migration %s', $platform::class, self::class));
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof SqlitePlatform) {
            $this->addSql('CREATE TEMPORARY TABLE __temp__recipe_ingredient AS SELECT id, quantity, unit, recipe_id, ingredient_id FROM recipe_ingredient');
            $this->addSql('DROP TABLE recipe_ingredient');
            $this->addSql('CREATE TABLE recipe_ingredient (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity NUMERIC(8, 2) NOT NULL, unit VARCHAR(20) NOT NULL, recipe_id INTEGER NOT NULL, ingredient_id INTEGER NOT NULL, CONSTRAINT FK_22D1FE1359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_22D1FE13933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('INSERT INTO recipe_ingredient (id, quantity, unit, recipe_id, ingredient_id) SELECT id, quantity, unit, recipe_id, ingredient_id FROM __temp__recipe_ingredient');
            $this->addSql('DROP TABLE __temp__recipe_ingredient');
            $this->addSql('CREATE INDEX IDX_22D1FE1359D8A214 ON recipe_ingredient (recipe_id)');
            $this->addSql('CREATE INDEX IDX_22D1FE13933FE08C ON recipe_ingredient (ingredient_id)');

            return;
        }

        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE recipe_ingredient DROP FOREIGN KEY FK_22D1FE13933FE08C');
            $this->addSql('ALTER TABLE recipe_ingredient CHANGE ingredient_id ingredient_id INT NOT NULL');
            $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE13933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id)');

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform "%s" for migration %s', $platform::class, self::class));
    }
}
