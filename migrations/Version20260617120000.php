<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds extension.slug (Gedmo-backed, unique) used as a clean route param for the
 * collection / extension pages instead of the raw UUID. Existing rows are
 * backfilled from their name (deduplicated) before the NOT NULL + unique index.
 */
final class Version20260617120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique extension.slug, backfilled from the name';
    }

    public function up(Schema $schema): void
    {
        // existing extensions to backfill (column does not exist yet → read name only)
        $extensions = $this->connection->fetchAllAssociative('SELECT id, name FROM extension');

        $this->addSql('ALTER TABLE extension ADD slug VARCHAR(255) DEFAULT NULL');

        $slugify = static function (string $text): string {
            $text = strtolower(trim($text));
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            $text = false !== $ascii ? $ascii : $text;
            $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

            return trim($text, '-');
        };

        $seen = [];
        foreach ($extensions as $extension) {
            $base = $slugify((string) $extension['name']);
            if ('' === $base) {
                $base = 'extension';
            }

            $slug = $base;
            $suffix = 2;
            while (isset($seen[$slug])) {
                $slug = $base . '-' . $suffix++;
            }
            $seen[$slug] = true;

            $this->addSql('UPDATE extension SET slug = ? WHERE id = ?', [$slug, $extension['id']]);
        }

        $this->addSql('ALTER TABLE extension ALTER COLUMN slug SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_extension_slug ON extension (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_extension_slug');
        $this->addSql('ALTER TABLE extension DROP slug');
    }
}
