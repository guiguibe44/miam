<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la catégorie sur les états de liste de courses.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $schemaManager = $this->connection->createSchemaManager();
        $columns = array_map(
            static fn ($column): string => strtolower($column->getName()),
            $schemaManager->listTableColumns('shopping_list_item_state')
        );
        $indexExists = false;
        foreach ($schemaManager->listTableIndexes('shopping_list_item_state') as $index) {
            if (strtolower($index->getName()) === 'uniq_shopping_state_period_item') {
                $indexExists = true;
                break;
            }
        }

        if (!in_array('category_key', $columns, true)) {
            $this->addSql("ALTER TABLE shopping_list_item_state ADD COLUMN category_key VARCHAR(40) NOT NULL DEFAULT 'autre'");
        }

        if ($indexExists) {
            if ($platform instanceof MySQLPlatform) {
                $this->addSql('DROP INDEX uniq_shopping_state_period_item ON shopping_list_item_state');
            } elseif ($platform instanceof SqlitePlatform) {
                $this->addSql('DROP INDEX uniq_shopping_state_period_item');
            } else {
                $this->abortIf(true, sprintf('Unsupported platform "%s" for migration %s', $platform::class, self::class));
            }
        }

        $this->addSql('CREATE UNIQUE INDEX uniq_shopping_state_period_item ON shopping_list_item_state (period_start, period_end, category_key, ingredient_key, unit_key)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $schemaManager = $this->connection->createSchemaManager();
        $columns = array_map(
            static fn ($column): string => strtolower($column->getName()),
            $schemaManager->listTableColumns('shopping_list_item_state')
        );
        $indexExists = false;
        foreach ($schemaManager->listTableIndexes('shopping_list_item_state') as $index) {
            if (strtolower($index->getName()) === 'uniq_shopping_state_period_item') {
                $indexExists = true;
                break;
            }
        }

        if ($indexExists) {
            if ($platform instanceof MySQLPlatform) {
                $this->addSql('DROP INDEX uniq_shopping_state_period_item ON shopping_list_item_state');
            } elseif ($platform instanceof SqlitePlatform) {
                $this->addSql('DROP INDEX uniq_shopping_state_period_item');
            } else {
                $this->abortIf(true, sprintf('Unsupported platform "%s" for migration %s', $platform::class, self::class));
            }
        }

        if (in_array('category_key', $columns, true)) {
            $this->addSql('CREATE UNIQUE INDEX uniq_shopping_state_period_item ON shopping_list_item_state (period_start, period_end, ingredient_key, unit_key)');
            $this->addSql('ALTER TABLE shopping_list_item_state DROP COLUMN category_key');
        }
    }
}
