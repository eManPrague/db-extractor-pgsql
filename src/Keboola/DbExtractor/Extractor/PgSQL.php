<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Extractor;

use Keboola\Csv\CsvFile;
use Keboola\DbExtractor\Exception\ApplicationException;
use Keboola\DbExtractor\Exception\UserException;
use Keboola\DbExtractor\RetryProxy;
use Keboola\Utils;
use Symfony\Component\Process\Process;
use PDO;
use PDOException;
use Throwable;

class PgSQL extends Extractor
{
    public const DEFAULT_MAX_TRIES = 5;

    /** @var  array */
    private $dbConfig;

    public function createConnection(array $dbParams): PDO
    {
        $this->dbConfig = $dbParams;

        // convert errors to PDOExceptions
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 60,
        ];

        // check params
        foreach (['host', 'database', 'user', 'password'] as $r) {
            if (!isset($dbParams[$r])) {
                throw new UserException(sprintf("Parameter %s is missing.", $r));
            }
        }

        $port = isset($dbParams['port']) ? $dbParams['port'] : '5432';

        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s",
            $dbParams['host'],
            $port,
            $dbParams['database']
        );

        $pdo = new PDO($dsn, $dbParams['user'], $dbParams['password'], $options);
        $pdo->exec("SET NAMES 'UTF8';");

        return $pdo;
    }

    public function createSshTunnel(array $dbConfig): array
    {
        $dbConfig['ssh']['compression'] = true;
        return parent::createSshTunnel($dbConfig);
    }

    private function restartConnection(): void
    {
        try {
            $this->db = $this->createConnection($this->dbConfig);
        } catch (Throwable $e) {
            throw new UserException(sprintf("Error connecting to DB: %s", $e->getMessage()), 0, $e);
        }
    }

    public function export(array $table): array
    {
        $outputTable = $table['outputTable'];

        $this->logger->info("Exporting to " . $outputTable);
        $advancedQuery = true;
        if (!isset($table['query']) || $table['query'] === '') {
            $query = $this->simpleQuery($table['table'], $table['columns']);
            $advancedQuery = false;
        } else {
            $query = $table['query'];
        }

        $copyFailed = false;
        $maxTries = isset($table['retries']) ? (int) $table['retries'] : self::DEFAULT_MAX_TRIES;
        $counter = 0;
        $exception = null;

        $csvCreated = false;


        try {
            $this->executeCopyQuery(
                $query,
                $this->createOutputCsv($outputTable),
                $table['outputTable'],
                $advancedQuery
            );
            $csvCreated = true;
        } catch (Throwable $copyError) {
            // There was an error, so let's try the old method
            if (!$copyError instanceof ApplicationException) {
                $this->logger->warning("Unexpected exception executing \copy: " . $copyError->getMessage());
            }
            try {
                // recreate the db connection
                $this->restartConnection();
            } catch (Throwable $connectionError) {
            };
            $proxy = new RetryProxy($this->logger, $maxTries);

            try {
                $csvCreated = $proxy->call(function () use ($query, $outputTable, $advancedQuery) {
                    try {
                        $this->executeQueryPDO($query, $this->createOutputCsv($outputTable), $advancedQuery);
                        return true;
                    } catch (Throwable $queryError) {
                        try {
                            $this->db = $this->createConnection($this->getDbParameters());
                        } catch (Throwable $connectionError) {
                        };
                        throw $queryError;
                    }
                });
            } catch (PDOException $pdoError) {
                throw new UserException(
                    sprintf(
                        "Error executing [%s]: %s",
                        $table['outputTable'],
                        $pdoError->getMessage()
                    )
                );
            } catch (Throwable $generalError) {
                throw($generalError);
            }
        }
        if ($csvCreated) {
            if ($this->createManifest($table) === false) {
                throw new ApplicationException(
                    "Unable to create manifest",
                    0,
                    null,
                    [
                    'table' => $table,
                    ]
                );
            }
        }

        return [
            "outputTable"=> $outputTable,
        ];
    }

    protected function executeQueryPDO(string $query, CsvFile $csv, bool $advancedQuery): void
    {
        $cursorName = 'exdbcursor' . intval(microtime(true));
        $curSql = "DECLARE $cursorName CURSOR FOR $query";
        $this->logger->info("Executing query via PDO ...");
        try {
            $this->db->beginTransaction(); // cursors require a transaction.
            $stmt = $this->db->prepare($curSql);
            $stmt->execute();
            $innerStatement = $this->db->prepare("FETCH 1 FROM $cursorName");
            $innerStatement->execute();
            // write header and first line
            $resultRow = $innerStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($resultRow) || empty($resultRow)) {
                $this->logger->warning("Query returned empty result. Nothing was imported");
                return;
            }
            // only write header for advanced query case
            if ($advancedQuery) {
                $csv->writeRow(array_keys($resultRow));
            }
            $csv->writeRow($resultRow);
            // write the rest
            $this->logger->info("Fetching data...");
            $innerStatement = $this->db->prepare("FETCH 10000 FROM $cursorName");
            while ($innerStatement->execute() && count($resultRows = $innerStatement->fetchAll(PDO::FETCH_ASSOC)) > 0) {
                foreach ($resultRows as $resultRow) {
                    $csv->writeRow($resultRow);
                }
            }
            // close the cursor
            $this->db->exec("CLOSE $cursorName");
            $this->db->commit();
            $this->logger->info("Extraction completed");
        } catch (PDOException $e) {
            try {
                $this->db->rollBack();
            } catch (Throwable $e2) {
            }
            $innerStatement = null;
            $stmt = null;
            throw $e;
        }
    }

    protected function executeCopyQuery(string $query, CsvFile $csvFile, string $tableName, bool $advancedQuery): void
    {
        $this->logger->info(sprintf("Executing query '%s' via \copy ...", $tableName));

        $copyCommand = "\COPY (%s) TO '%s' WITH CSV DELIMITER ',' FORCE QUOTE *;";
        if ($advancedQuery) {
            $copyCommand = "\COPY (%s) TO '%s' WITH CSV HEADER DELIMITER ',' FORCE QUOTE *;";
        }

        $command = sprintf(
            "PGPASSWORD='%s' psql -h %s -p %s -U %s -d %s -w -c %s",
            $this->dbConfig['password'],
            $this->dbConfig['host'],
            $this->dbConfig['port'],
            $this->dbConfig['user'],
            $this->dbConfig['database'],
            escapeshellarg(
                sprintf(
                    $copyCommand,
                    rtrim($query, '; '),
                    $csvFile
                )
            )
        );

        $process = new Process($command);
        // allow it to run for as long as it needs
        $process->setTimeout(null);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->logger->info("Failed \copy command (will attempt via PDO): " . $process->getErrorOutput());
            throw new ApplicationException("Error using copy command.", 42);
        }
    }

    public function testConnection(): void
    {
        // check PDO connection
        $this->db->query("SELECT 1");

        // check psql connection
        $command = sprintf(
            "PGPASSWORD='%s' psql -h %s -p %s -U %s -d %s -w -c \"SELECT 1;\"",
            $this->dbConfig['password'],
            $this->dbConfig['host'],
            $this->dbConfig['port'],
            $this->dbConfig['user'],
            $this->dbConfig['database']
        );
        $process = new Process($command);
        $process->run();
        if ($process->getExitCode() !== 0) {
            throw new UserException("Failed psql connection: " . $process->getErrorOutput());
        }
    }

    public function getTables(?array $tables = null): array
    {
        $sql = "SELECT * FROM information_schema.tables
                WHERE table_schema != 'pg_catalog' AND table_schema != 'information_schema'";

        $additionalWhereClause = '';
        if (!is_null($tables) && count($tables) > 0) {
            $additionalWhereClause = sprintf(
                " AND table_name IN (%s) AND table_schema IN (%s)",
                implode(
                    ',',
                    array_map(
                        function ($table) {
                            return $this->db->quote($table['tableName']);
                        },
                        $tables
                    )
                ),
                implode(
                    ',',
                    array_map(
                        function ($table) {
                            return $this->db->quote($table['schema']);
                        },
                        $tables
                    )
                )
            );
        }

        $sql .= $additionalWhereClause;

        $res = $this->db->query($sql);
        $arr = $res->fetchAll(PDO::FETCH_ASSOC);

        $tableNameArray = [];
        $tableDefs = [];
        foreach ($arr as $table) {
            $tableNameArray[] = $table['table_name'];
            $tableDefs[$table['table_schema'] . '.' . $table['table_name']] = [
                'name' => $table['table_name'],
                'schema' => $table['table_schema'] ?? null,
                'type' => $table['table_type'] ?? null,
            ];
        }

        ksort($tableDefs);

        if (count($tableNameArray) === 0) {
            return [];
        }

        $sql = <<<EOT
    SELECT 
      ns.nspname AS table_schema,
      c.relname AS table_name,
      a.attname AS column_name,
      format_type(a.atttypid, a.atttypmod) AS data_type_with_length,
      NOT a.attnotnull AS nullable,
      i.indisprimary AS primary_key,
      a.attnum AS ordinal_position,
      d.adsrc AS default_value
    FROM pg_attribute a
    JOIN pg_class c ON a.attrelid = c.oid AND c.reltype != 0 --indexes have 0 reltype, we don't want them here
    INNER JOIN pg_namespace ns ON ns.oid = c.relnamespace --schemas
    LEFT JOIN pg_index i ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) -- PKs
    LEFT JOIN pg_catalog.pg_attrdef d ON (a.attrelid, a.attnum) = (d.adrelid,  d.adnum) -- default values
    WHERE 
      NOT a.attisdropped -- exclude dropped columns
      AND a.attnum > 0 -- exclude system columns
      AND ns.nspname != 'information_schema' -- exclude system namespaces
      AND ns.nspname != 'pg_catalog' 
      AND ns.nspname NOT LIKE 'pg_toast%'
      AND ns.nspname NOT LIKE 'pg_temp%'
EOT;
        if (!is_null($tables) && count($tables) > 0) {
            $sql .= sprintf(
                " AND c.relname IN (%s) AND ns.nspname IN (%s)",
                implode(
                    ',',
                    array_map(
                        function ($table) {
                            return $this->db->quote($table['tableName']);
                        },
                        $tables
                    )
                ),
                implode(
                    ',',
                    array_map(
                        function ($table) {
                            return $this->db->quote($table['schema']);
                        },
                        $tables
                    )
                )
            );
        }

        $res = $this->db->query($sql);
        while ($column = $res->fetch(PDO::FETCH_ASSOC)) {
            $curTable = $column['table_schema'] . '.' . $column['table_name'];

            $ret = preg_match('/(.*)\((\d+|\d+,\d+)\)/', $column['data_type_with_length'], $parsedType);

            $data_type = $column['data_type_with_length'];
            $length = null;
            if ($ret === 1) {
                $data_type = isset($parsedType[1]) ? $parsedType[1] : null;
                $length = isset($parsedType[2]) ? $parsedType[2] : null;
            }

            $default = $column['default_value'];
            if ($data_type === 'character varying' && $default !== null) {
                $default = str_replace("'", "", explode("::", $column['default_value'])[0]);
            }
            $tableDefs[$curTable]['columns'][$column['ordinal_position'] - 1] = [
                "name" => $column['column_name'],
                "sanitizedName" => Utils\sanitizeColumnName($column['column_name']),
                "type" => $data_type,
                "primaryKey" => $column['primary_key'] ?: false,
                "length" => $length,
                "nullable" => $column['nullable'],
                "default" => $default,
                "ordinalPosition" => $column['ordinal_position'],
            ];

            // make sure columns are sorted by index which is ordinal_position - 1
            ksort($tableDefs[$curTable]['columns']);
        }
        return array_values($tableDefs);
    }

    public function simpleQuery(array $table, array $columns = []): string
    {
        if (count($columns) > 0) {
            return sprintf(
                "SELECT %s FROM %s.%s",
                implode(
                    ', ',
                    array_map(
                        function ($column) {
                            return $this->quote($column);
                        },
                        $columns
                    )
                ),
                $this->quote($table['schema']),
                $this->quote($table['tableName'])
            );
        } else {
            return sprintf(
                "SELECT * FROM %s.%s",
                $this->quote($table['schema']),
                $this->quote($table['tableName'])
            );
        }
    }
    private function quote(string $obj): string
    {
        return "\"{$obj}\"";
    }
}
