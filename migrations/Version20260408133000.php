<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le champ steps sur recipe.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD COLUMN steps CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP COLUMN steps');
    }
}
