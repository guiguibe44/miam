<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Memorise l usage des recettes en planification (compteur + derniere date).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $schemaManager = $this->connection->createSchemaManager();
        $columns = array_map(
            static fn ($column): string => strtolower($column->getName()),
            $schemaManager->listTableColumns('recipe')
        );

        if (!in_array('planning_selection_count', $columns, true)) {
            $this->addSql('ALTER TABLE recipe ADD planning_selection_count INT NOT NULL DEFAULT 0');
        }

        if (!in_array('planning_last_selected_at', $columns, true)) {
            if ($platform instanceof MySQLPlatform) {
                $this->addSql('ALTER TABLE recipe ADD planning_last_selected_at DATETIME DEFAULT NULL');
            } elseif ($platform instanceof SqlitePlatform) {
                $this->addSql('ALTER TABLE recipe ADD COLUMN planning_last_selected_at DATETIME DEFAULT NULL');
            } else {
                $this->abortIf(true, sprintf('Unsupported platform "%s" for migration %s', $platform::class, self::class));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $columns = array_map(
            static fn ($column): string => strtolower($column->getName()),
            $schemaManager->listTableColumns('recipe')
        );

        if (in_array('planning_last_selected_at', $columns, true)) {
            $this->addSql('ALTER TABLE recipe DROP COLUMN planning_last_selected_at');
        }

        if (in_array('planning_selection_count', $columns, true)) {
            $this->addSql('ALTER TABLE recipe DROP COLUMN planning_selection_count');
        }
    }
}
