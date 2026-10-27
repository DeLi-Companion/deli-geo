<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Loading;

use DeLiCompanion\Geo\Support\Query;
use InvalidArgumentException;
use Pdo\Pgsql;
use RuntimeException;

final readonly class GeoSchema
{
    /**
     * @var list<string>
     */
    public const array SUFFIXES = ['', '_next', '_previous'];

    /**
     * @var array<string, list<string>>
     */
    private const array INDEXES = [
        'geo_addresses' => ['lookup_index', 'location_index'],
        'geo_streets' => ['lookup_index', 'street_name_trigram_index'],
    ];

    public function __construct(
        private Pgsql $connection,
    ) {}

    public function createExtensions(): void
    {
        $this->connection->exec('CREATE EXTENSION IF NOT EXISTS postgis; CREATE EXTENSION IF NOT EXISTS pg_trgm;');
    }

    public function createStateTable(): void
    {
        $this->connection->exec($this->sql('state.sql'));
    }

    public function createLiveTables(): void
    {
        $this->createExtensions();
        $this->createStateTable();
        $this->createTables('');
        $this->createIndexes('');
    }

    public function createTables(string $suffix): void
    {
        $this->connection->exec($this->sql('tables.sql', $suffix));
    }

    public function createIndexes(string $suffix): void
    {
        $this->connection->exec($this->sql('indexes.sql', $suffix));
    }

    public function dropTables(string $suffix): void
    {
        $this->assertSuffix($suffix);
        $this->connection->exec("DROP TABLE IF EXISTS geo_addresses{$suffix}, geo_streets{$suffix}");
    }

    public function tablesExist(string $suffix): bool
    {
        $this->assertSuffix($suffix);

        return (int) Query::scalar(
            $this->connection,
            'SELECT (to_regclass(:addresses) IS NOT NULL AND to_regclass(:streets) IS NOT NULL)::int',
            ['addresses' => 'geo_addresses'.$suffix, 'streets' => 'geo_streets'.$suffix],
        ) === 1;
    }

    public function renameTables(string $fromSuffix, string $toSuffix): void
    {
        $this->assertSuffix($fromSuffix);
        $this->assertSuffix($toSuffix);

        $statements = [];
        foreach (self::INDEXES as $table => $indexes) {
            $statements[] = "ALTER TABLE {$table}{$fromSuffix} RENAME TO {$table}{$toSuffix}";
            foreach ($indexes as $index) {
                $statements[] = "ALTER INDEX {$table}{$fromSuffix}_{$index} RENAME TO {$table}{$toSuffix}_{$index}";
            }
        }

        $this->connection->exec(implode(";\n", $statements));
    }

    private function sql(string $file, string $suffix = ''): string
    {
        $this->assertSuffix($suffix);
        $path = dirname(__DIR__, 2).'/sql/'.$file;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return str_replace('{suffix}', $suffix, $contents);
    }

    private function assertSuffix(string $suffix): void
    {
        if (! in_array($suffix, self::SUFFIXES, true)) {
            throw new InvalidArgumentException("Unknown geo table suffix \"{$suffix}\".");
        }
    }
}
