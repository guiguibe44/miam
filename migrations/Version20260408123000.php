<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la catégorie sur les états de liste de courses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE shopping_list_item_state ADD COLUMN category_key VARCHAR(40) NOT NULL DEFAULT 'autre'");
        $this->addSql('DROP INDEX uniq_shopping_state_period_item');
        $this->addSql('CREATE UNIQUE INDEX uniq_shopping_state_period_item ON shopping_list_item_state (period_start, period_end, category_key, ingredient_key, unit_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_shopping_state_period_item');
        $this->addSql('CREATE UNIQUE INDEX uniq_shopping_state_period_item ON shopping_list_item_state (period_start, period_end, ingredient_key, unit_key)');
        $this->addSql('ALTER TABLE shopping_list_item_state DROP COLUMN category_key');
    }
}
