<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 10/02/16
 * Time: 17:49
 */

namespace Keboola\DbExtractor\Extractor;

use Keboola\Csv\CsvFile;
use Keboola\DbExtractor\Exception\ApplicationException;
use Keboola\DbExtractor\Exception\UserException;
use Symfony\Component\Process\Process;

class PgSQL extends Extractor
{
    private $dbConfig;

    public function createConnection($dbParams)
    {
        $this->dbConfig = $dbParams;

        // convert errors to PDOExceptions
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
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

        $pdo = new \PDO($dsn, $dbParams['user'], $dbParams['password'], $options);
        $pdo->exec("SET NAMES 'UTF8';");

        return $pdo;
    }

    private function restartConnection()
    {
        $this->db = null;
        try {
            $this->db = $this->createConnection($this->dbConfig);
        } catch (\Exception $e) {
            throw new UserException(sprintf("Error connecting to DB: %s", $e->getMessage()), 0, $e);
        }
    }

    public function export(array $table)
    {
        $outputTable = $table['outputTable'];

        $this->logger->info("Exporting to " . $outputTable);

        if (!isset($table['query']) || $table['query'] === '') {
            $query = $this->simpleQuery($table['table'], $table['columns']);
        } else {
            $query = $table['query'];
        }

        $copyFailed = false;
        $tries = 0;
        $exception = null;

        $csvCreated = false;
        while ($tries < 5) {
            $exception = null;
            try {
                if ($tries > 0) {
                    $this->logger->info("Retrying query");
                    $this->restartConnection();
                }
                if (!$copyFailed) {
                    try {
                        $csvCreated = $this->executeQuery($query, $this->createOutputCsv($outputTable));
                    } catch (ApplicationException $applicationException) {
                        // There was an error, so let's try the old method
                        if ($applicationException->getCode() === 42) {
                            $copyFailed = true;
                            continue;
                        }
                        throw $applicationException;
                    }
                } else {
                    $csvCreated = $this->executeQueryPDO($query, $this->createOutputCsv($outputTable));
                }
                break;
            } catch (\PDOException $e) {
                $exception = new UserException("DB query [{$table['name']}] failed: " . $e->getMessage(), 0, $e);
            }
            sleep(pow($tries, 2));
            $tries++;
        }

        if ($exception) {
            throw $exception;
        }

        if ($csvCreated) {
            if ($this->createManifest($table) === false) {
                throw new ApplicationException("Unable to create manifest", 0, null, [
                    'table' => $table
                ]);
            }
        }

        return $outputTable;
    }

    protected function executeQueryPDO($query, CsvFile $csv)
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
            $resultRow = $innerStatement->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($resultRow) || empty($resultRow)) {
                $this->logger->warning("Query returned empty result. Nothing was imported");
                return false;
            }
            $csv->writeRow(array_keys($resultRow));
            if (isset($this->dbConfig['replaceNull'])) {
                $resultRow = $this->replaceNull($resultRow, $this->dbConfig['replaceNull']);
            }
            $csv->writeRow($resultRow);
            // write the rest
            $this->logger->info("Fetching data...");
            $innerStatement = $this->db->prepare("FETCH 10000 FROM $cursorName");
            while ($innerStatement->execute() && count($resultRows = $innerStatement->fetchAll(\PDO::FETCH_ASSOC)) > 0) {
                foreach ($resultRows as $resultRow) {
                    $csv->writeRow($resultRow);
                }
            }
            // close the cursor
            $this->db->exec("CLOSE $cursorName");
            $this->db->commit();
            $this->logger->info("Extraction completed");
            return true;
        } catch (\PDOException $e) {
            try {
                $this->db->rollBack();
            } catch (\Exception $e2) {
            }
            $innerStatement = null;
            $stmt = null;
            throw $e;
        }
    }

    protected function executeQuery($query, CsvFile $csvFile)
    {
        $this->logger->info("Executing query via \copy ...");

        $command = sprintf(
            "PGPASSWORD='%s' psql -h %s -p %s -U %s -d %s -w -c %s",
            $this->dbConfig['password'],
            $this->dbConfig['host'],
            $this->dbConfig['port'],
            $this->dbConfig['user'],
            $this->dbConfig['database'],
            escapeshellarg(
                sprintf(
                    "\COPY (%s) TO '%s' WITH CSV HEADER DELIMITER ',' FORCE QUOTE *;",
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
        return true;
    }

    public function testConnection()
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

    public function getTables(array $tables = null)
    {
        $sql = "SELECT * FROM information_schema.tables 
                WHERE table_schema != 'pg_catalog' AND table_schema != 'information_schema'";

        if (!is_null($tables) && count($tables) > 0) {
            $sql .= sprintf(
                " AND TABLE_NAME IN (%s) AND TABLE_SCHEMA IN (%s)",
                implode(',', array_map(function ($table) {
                    return $this->db->quote($table['tableName']);
                }, $tables)),
                implode(',', array_map(function ($table) {
                    return $this->db->quote($table['schema']);
                }, $tables))
            );
        }

        $sql .= " ORDER BY table_schema, table_name";
        
        $res = $this->db->query($sql);
        $arr = $res->fetchAll(\PDO::FETCH_ASSOC);
        $tableNameArray = [];
        $tableDefs = [];
        foreach ($arr as $table) {
            $tableNameArray[] = $table['table_name'];
            $tableDefs[$table['table_name']] = [
                'name' => $table['table_name'],
                'schema' => (isset($table['table_schema'])) ? $table['table_schema'] : null,
                'type' => (isset($table['table_type'])) ? $table['table_type'] : null
            ];
        }

        if (count($tableNameArray) === 0) {
            return [];
        }

        $sql = sprintf(
            "SELECT c.*, c.column_name as column_name, column_default, is_nullable, data_type, ordinal_position
                        character_maximum_length, numeric_precision, numeric_scale, is_identity, 
                        fks.constraint_type, fks.constraint_name, fks.foreign_table_name, fks.foreign_column_name, 
                        pga.atttypmod - 4 as varlength         
                    FROM information_schema.columns as c 
                    LEFT JOIN pg_catalog.pg_attribute as pga 
                      ON c.table_name::regclass = pga.attrelid AND c.column_name = pga.attname 
                    LEFT JOIN (
                      SELECT
                            tc.constraint_type, tc.constraint_name, tc.table_name, tc.constraint_schema, kcu.column_name,
                            ccu.table_name AS foreign_table_name, ccu.column_name AS foreign_column_name 
                        FROM 
                            information_schema.table_constraints AS tc 
                            JOIN information_schema.key_column_usage AS kcu
                              ON tc.constraint_name = kcu.constraint_name
                            JOIN information_schema.constraint_column_usage AS ccu
                              ON ccu.constraint_name = tc.constraint_name
                        WHERE tc.constraint_type IN ('PRIMARY KEY', 'FOREIGN KEY')
                    ) as fks                    
                    ON c.table_schema = fks.constraint_schema AND fks.table_name = c.table_name AND fks.column_name = c.column_name 
                    WHERE c.table_name IN (%s) ORDER BY c.table_schema, c.table_name, ordinal_position",
            implode(',', array_map(function ($table) {
                return $this->db->quote($table);
            }, $tableNameArray))
        );

        $res = $this->db->query($sql);

        while ($column = $res->fetch(\PDO::FETCH_ASSOC)) {
            $length = ($column['data_type'] === 'character varying') ? $column['varlength'] : null;
            if (is_null($length) && !is_null($column['numeric_precision'])) {
                if ($column['numeric_scale'] > 0) {
                    $length = $column['numeric_precision'] . "," . $column['numeric_scale'];
                } else {
                    $length = $column['numeric_precision'];
                }
            }
            $default = $column['column_default'];
            if ($column['data_type'] === 'character varying' && $default !== null) {
                $default = str_replace("'", "", explode("::", $column['column_default'])[0]);
            }
            $tableDefs[$column['table_name']]['columns'][$column['ordinal_position'] - 1] = [
                "name" => $column['column_name'],
                "type" => $column['data_type'],
                "primaryKey" => ($column['constraint_type'] === "PRIMARY KEY") ? true : false,
                "length" => $length,
                "nullable" => ($column['is_nullable'] === "NO") ? false : true,
                "default" => $default,
                "ordinalPosition" => $column['ordinal_position']
            ];

            if ($column['constraint_type'] === "FOREIGN KEY") {
                $tableDefs[$column['table_name']]['columns'][$column['ordinal_position'] - 1]['foreignKeyRefTable'] =
                    $column['foreign_table_name'];
                $tableDefs[$column['table_name']]['columns'][$column['ordinal_position'] - 1]['foreignKeyRefColumn'] =
                    $column['foreign_column_name'];
                $tableDefs[$column['table_name']]['columns'][$column['ordinal_position'] - 1]['foreignKeyRef'] =
                    $column['constraint_name'];
            }
        }

        return array_values($tableDefs);
    }

    protected function describeTable(array $table)
    {
        // Deprecated
        return null;
    }

    public function simpleQuery(array $table, array $columns = array())
    {
        if (count($columns) > 0) {
            return sprintf("SELECT %s FROM %s.%s",
                implode(', ', array_map(function ($column) {
                    return $this->quote($column);
                }, $columns)),
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
    private function quote($obj) {
        return "\"{$obj}\"";
    }
}
