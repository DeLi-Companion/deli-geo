<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Support;

use PDO;
use Pdo\Pgsql;
use PDOStatement;
use RuntimeException;

final class Query
{
    /**
     * @param  array<string, string|int|float|null>  $parameters
     */
    public static function execute(Pgsql $connection, string $sql, array $parameters = []): PDOStatement
    {
        $statement = $connection->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Cannot prepare a geo query.');
        }

        $statement->execute($parameters);

        return $statement;
    }

    /**
     * @param  array<string, string|int|float|null>  $parameters
     */
    public static function scalar(Pgsql $connection, string $sql, array $parameters = []): mixed
    {
        return self::execute($connection, $sql, $parameters)->fetchColumn();
    }

    /**
     * @param  array<string, string|int|float|null>  $parameters
     * @return array<string, mixed>|null
     */
    public static function row(Pgsql $connection, string $sql, array $parameters = []): ?array
    {
        $row = self::execute($connection, $sql, $parameters)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param  array<string, string|int|float|null>  $parameters
     * @return list<array<string, mixed>>
     */
    public static function rows(Pgsql $connection, string $sql, array $parameters = []): array
    {
        return array_values(self::execute($connection, $sql, $parameters)->fetchAll(PDO::FETCH_ASSOC));
    }
}
