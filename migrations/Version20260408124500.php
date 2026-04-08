<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408124500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la catégorie sur recipe_ingredient.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE recipe_ingredient ADD COLUMN category VARCHAR(40) NOT NULL DEFAULT 'autre'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe_ingredient DROP COLUMN category');
    }
}
