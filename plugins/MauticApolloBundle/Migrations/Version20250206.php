<?php

declare(strict_types=1);

namespace MauticPlugin\ApolloBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add attempts and nextAttemptAt to apollo_queue for retry/backoff support';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('apollo_queue');

        if (!$table->hasColumn('attempts')) {
            $table->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
        }

        if (!$table->hasColumn('next_attempt_at')) {
            $table->addColumn('next_attempt_at', 'datetime', ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('apollo_queue');
        if ($table->hasColumn('attempts')) {
            $table->dropColumn('attempts');
        }
        if ($table->hasColumn('next_attempt_at')) {
            $table->dropColumn('next_attempt_at');
        }
    }
}
