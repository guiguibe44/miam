<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute recipe_origin (Maison / Jow / 750g) sur les recettes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE recipe ADD recipe_origin VARCHAR(16) DEFAULT 'maison' NOT NULL");
        $this->addSql('UPDATE recipe SET recipe_origin = CASE
            WHEN source_url IS NOT NULL AND source_url LIKE \'%jow.fr%\' THEN \'jow\'
            WHEN source_url IS NOT NULL AND source_url LIKE \'%750g.com%\' THEN \'750g\'
            ELSE \'maison\'
            END');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP recipe_origin');
    }
}
