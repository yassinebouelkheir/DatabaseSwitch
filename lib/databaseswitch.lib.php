<?php
/**
 * DatabaseSwitch — Bibliothèque principale de migration
 * Auteur : BOUELKHEIR Yassine
 *
 * Garanties de sécurité :
 *  1. La base SOURCE n'est jamais modifiée
 *  2. conf.php n'est jamais touché avant que la vérification soit à 100%
 *  3. La migration passe toujours par une base STAGING (nom horodaté)
 *  4. Les anciennes bases staging sont nettoyées automatiquement
 *  5. Le swap renomme staging → production et patche conf.php avec backup
 *  6. Le rollback restaure conf.php depuis son backup
 *  7. Chaque étape est idempotente — relancer ne casse rien
 */
class DatabaseSwitchLib
{

    public static function runShellCommand(string $command): array
    {
        $outputLines = [];
        $exitCode    = 0;
        exec($command.' 2>&1', $outputLines, $exitCode);
        return [
            'ok'       => $exitCode === 0,
            'output'   => implode("\n", $outputLines),
            'exitCode' => $exitCode,
        ];
    }

    public static function commandExists(string $commandName): bool
    {
        return self::runShellCommand('which '.escapeshellarg($commandName))['ok'];
    }

    public static function mysqlConnect(array $config): ?\mysqli
    {
        $connection = @mysqli_connect($config['host'], $config['user'], $config['pass'], $config['db'], (int)$config['port']);
        return $connection ?: null;
    }

    public static function mysqlFetchAll(\mysqli $connection, string $sql): array
    {
        $rows   = [];
        $result = mysqli_query($connection, $sql);
        if (!$result || $result === true) return $rows;
        while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
        return $rows;
    }

    public static function mysqlFetchScalar(\mysqli $connection, string $sql): mixed
    {
        $result = mysqli_query($connection, $sql);
        if (!$result) return null;
        $row = mysqli_fetch_row($result);
        return $row[0] ?? null;
    }

    public static function mysqlGetTableNames(\mysqli $connection, string $databaseName, string $tablePrefix): array
    {
        $rows = self::mysqlFetchAll($connection,
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '".mysqli_real_escape_string($connection, $databaseName)."'
               AND TABLE_NAME LIKE '".mysqli_real_escape_string($connection, $tablePrefix)."%'
             ORDER BY TABLE_NAME"
        );
        return array_column($rows, 'TABLE_NAME');
    }

    public static function mysqlCountRows(\mysqli $connection, string $tableName): int
    {
        return (int)(self::mysqlFetchScalar($connection, "SELECT COUNT(*) FROM `{$tableName}`") ?? 0);
    }

    public static function pgConnect(array $config): ?\PDO
    {
        try {
            $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['db']}";
            return new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 10,
            ]);
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function pgConnectAsSuperuser(string $host, string $port, string $fallbackUser = '', string $fallbackPassword = ''): ?\PDO
    {
        $dsn = "pgsql:host={$host};port={$port};dbname=postgres";

        $credentialsToTry = [
            ['postgres', ''],
            ['postgres', 'postgres'],
        ];
        if ($fallbackUser) {
            $credentialsToTry[] = [$fallbackUser, $fallbackPassword];
        }

        foreach ($credentialsToTry as [$user, $password]) {
            try {
                return new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
            } catch (\Exception $e) {

            }
        }
        return null;
    }

    public static function pgExecute(\PDO $connection, string $sql, bool $ignoreErrors = false): bool
    {
        try {
            $connection->exec($sql);
            return true;
        } catch (\Exception $e) {
            if ($ignoreErrors) return false;
            throw $e;
        }
    }

    public static function pgFetchScalar(\PDO $connection, string $sql): mixed
    {
        try {
            $stmt = $connection->query($sql);
            $row  = $stmt->fetch(PDO::FETCH_NUM);
            return $row[0] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function pgGetTableNames(\PDO $connection, string $tablePrefix): array
    {

        $stmt = $connection->prepare(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
               AND table_type = 'BASE TABLE'
               AND table_name LIKE ?
             ORDER BY table_name"
        );
        $stmt->execute([$tablePrefix.'%']);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'table_name');
    }

    public static function pgCountRows(\PDO $connection, string $tableName): int
    {

        $schema = self::pgFetchScalar($connection,
            "SELECT table_schema FROM information_schema.tables
             WHERE table_schema NOT IN ('pg_catalog','information_schema','pg_toast')
               AND table_type = 'BASE TABLE'
               AND table_name = '".addslashes($tableName)."' LIMIT 1"
        ) ?? 'public';
        return (int)(self::pgFetchScalar($connection, "SELECT COUNT(*) FROM \"{$schema}\".\"{$tableName}\"") ?? 0);
    }

    public static function pgDatabaseExists(\PDO $connection, string $databaseName): bool
    {
        $result = self::pgFetchScalar($connection, "SELECT 1 FROM pg_database WHERE datname = '".addslashes($databaseName)."'");
        return $result === '1' || $result === 1;
    }

    public static function pgRoleExists(\PDO $connection, string $roleName): bool
    {
        $result = self::pgFetchScalar($connection, "SELECT 1 FROM pg_roles WHERE rolname = '".addslashes($roleName)."'");
        return $result === '1' || $result === 1;
    }

    public static function pgResetAllSequences(\PDO $connection, string $tablePrefix): array
    {
        $logLines     = [];
        $resetCount   = 0;

        $stmt = $connection->query(
            "SELECT table_name, column_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name LIKE '".addslashes($tablePrefix)."%'
               AND column_default LIKE 'nextval%'
             ORDER BY table_name, column_name"
        );
        $columnsWithSequences = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columnsWithSequences as $col) {
            $tableName  = $col['table_name'];
            $columnName = $col['column_name'];

            try {
                $sequenceName = self::pgFetchScalar($connection,
                    "SELECT pg_get_serial_sequence('\"$tableName\"', '$columnName')"
                );
                if (!$sequenceName) continue;

                $currentMax = (int)(self::pgFetchScalar($connection,
                    "SELECT COALESCE(MAX(\"{$columnName}\"), 0) FROM \"{$tableName}\""
                ) ?? 0);
                $nextValue  = max($currentMax, 0) + 1;

                self::pgExecute($connection, "SELECT SETVAL('{$sequenceName}', {$nextValue}, false)");
                $logLines[] = "  ↺ {$tableName}.{$columnName} → prochain = {$nextValue}";
                $resetCount++;
            } catch (\Exception $e) {
                $logLines[] = "  ⚠ {$tableName}.{$columnName} : ".$e->getMessage();
            }
        }

        return ['count' => $resetCount, 'logLines' => $logLines];
    }

    public static function generateStagingDbName(string $productionDbName): string
    {
        return $productionDbName.'_mig_'.date('Ymd_His');
    }

    public static function pgDropOldStagingDatabases(\PDO $superuserConnection, string $productionDbName): array
    {
        $logLines = [];

        $stmt     = $superuserConnection->query(
            "SELECT datname FROM pg_database WHERE datname LIKE '".addslashes($productionDbName)."_mig_%'"
            ." OR datname LIKE '".addslashes($productionDbName)."_old_%'"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oldStagingName) {
            try {

                $superuserConnection->exec(
                    "SELECT pg_terminate_backend(pid)
                     FROM pg_stat_activity
                     WHERE datname = '{$oldStagingName}' AND pid <> pg_backend_pid()"
                );
                $superuserConnection->exec("DROP DATABASE IF EXISTS \"{$oldStagingName}\"");
                $logLines[] = "Ancienne staging supprimée : {$oldStagingName}";
            } catch (\Exception $e) {
                $logLines[] = "Impossible de supprimer {$oldStagingName} : ".$e->getMessage();
            }
        }
        return $logLines;
    }

    public static function mysqlDropOldStagingDatabases(\mysqli $connection, string $productionDbName): array
    {
        $logLines   = [];
        $escaped    = mysqli_real_escape_string($connection, $productionDbName);

        $candidates = self::mysqlFetchAll($connection,
            "SELECT SCHEMA_NAME
             FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME LIKE '{$escaped}_mig_%'
                OR SCHEMA_NAME LIKE '{$escaped}_old_%'"
        );

        foreach ($candidates as $row) {
            $oldStagingName = $row['SCHEMA_NAME'];
            mysqli_query($connection, "DROP DATABASE IF EXISTS `{$oldStagingName}`");
            $logLines[] = "Ancienne staging supprimée : {$oldStagingName}";
        }
        return $logLines;
    }

    public static function readDolibarrConf(string $confFilePath): array
    {
        if (!file_exists($confFilePath)) return [];

        $content       = file_get_contents($confFilePath);
        $parameterKeys = [
            'dolibarr_main_db_type',
            'dolibarr_main_db_host',
            'dolibarr_main_db_port',
            'dolibarr_main_db_name',
            'dolibarr_main_db_user',
            'dolibarr_main_db_pass',
            'dolibarr_main_db_prefix',
        ];

        $parameters = [];
        foreach ($parameterKeys as $key) {
            if (preg_match('/\$'.preg_quote($key, '/').'\\s*=\\s*[\'"]([^\'"]*)[\'"]/', $content, $matches)) {
                $parameters[$key] = $matches[1];
            }
        }
        return $parameters;
    }

    public static function makeConfWritable(string $confFilePath): bool
    {
        clearstatcache(true, $confFilePath);
        if (is_writable($confFilePath)) return true;

        @chmod($confFilePath, 0640);
        clearstatcache(true, $confFilePath);
        if (is_writable($confFilePath)) return true;

        if (function_exists('exec')) {
            $chownBin = file_exists('/usr/bin/chown') ? '/usr/bin/chown' : '/bin/chown';
            @exec('sudo '.$chownBin.' www-data:www-data '.escapeshellarg($confFilePath).' 2>&1');
            @exec('sudo chmod 640 '.escapeshellarg($confFilePath).' 2>&1');
            clearstatcache(true, $confFilePath);
            if (is_writable($confFilePath)) return true;
        }

        return false;
    }

    public static function patchDolibarrConf(string $confFilePath, array $newValues, string $backupDirectory): string
    {

        if (!file_exists($confFilePath)) {
            throw new \RuntimeException("conf.php introuvable : {$confFilePath}");
        }
        if (!is_readable($confFilePath)) {
            throw new \RuntimeException("conf.php non lisible (vérifiez les permissions) : {$confFilePath}");
        }
        if (!self::makeConfWritable($confFilePath)) {
            throw new \RuntimeException(
                "conf.php non modifiable par le process web (www-data).\n"
                ."Corrigez les permissions avec :\n"
                ."  sudo chown www-data:www-data {$confFilePath}\n"
                ."  sudo chmod 640 {$confFilePath}"
            );
        }

        $backupFilePath = $backupDirectory.'/conf.php.bak.'.date('Ymd_His');

        $originalContent = file_get_contents($confFilePath);
        if ($originalContent === false) {
            throw new \RuntimeException("Impossible de lire conf.php pour le backup");
        }
        $backupWritten = @file_put_contents($backupFilePath, $originalContent);
        if ($backupWritten === false) {

            $backupFilePath = dirname($confFilePath).'/conf.php.bak.'.date('Ymd_His');
            $backupWritten = @file_put_contents($backupFilePath, $originalContent);
            if ($backupWritten === false) {
                throw new \RuntimeException("Impossible de créer le backup de conf.php");
            }
        }

        $content = file_get_contents($confFilePath);
        foreach ($newValues as $key => $value) {
            $escapedValue = addslashes($value);

            $pattern = '/\$'.preg_quote($key, '/').' *=\s*[\'"][^\'"]*[\'"]\s*;/';
            $replacement = "\${$key}='{$escapedValue}';";
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $replacement, $content);
            } else {

                $content = rtrim($content)."\n\${$key}='{$escapedValue}';\n";
            }
        }

        $bytesWritten = file_put_contents($confFilePath, $content);
        if ($bytesWritten === false) {
            throw new \RuntimeException("Échec de l'écriture dans conf.php");
        }

        self::makeConfWritable($confFilePath);

        return $backupFilePath;
    }

    public static function restoreDolibarrConf(string $backupFilePath, string $confFilePath): bool
    {
        if (!file_exists($backupFilePath)) return false;
        $content = file_get_contents($backupFilePath);
        if ($content === false) return false;
        $result = file_put_contents($confFilePath, $content) !== false;

        if ($result) self::makeConfWritable($confFilePath);
        return $result;
    }

    public static function cleanupOldBackups(string $backupDirectory, int $keepCount = 5): array
    {
        $logLines = [];
        if (!is_dir($backupDirectory)) return $logLines;

        $sqlFiles = glob($backupDirectory.'/*_backup_*.sql.gz');
        if ($sqlFiles && count($sqlFiles) > $keepCount) {
            usort($sqlFiles, function($a, $b) { return filemtime($b) - filemtime($a); });
            foreach (array_slice($sqlFiles, $keepCount) as $oldFile) {
                @unlink($oldFile);
                $logLines[] = "Ancien backup supprimé : ".basename($oldFile);
            }
        }

        $confFiles = glob($backupDirectory.'/conf.php.bak.*');
        if ($confFiles && count($confFiles) > $keepCount) {
            usort($confFiles, function($a, $b) { return filemtime($b) - filemtime($a); });
            foreach (array_slice($confFiles, $keepCount) as $oldFile) {
                @unlink($oldFile);
                $logLines[] = "Ancien backup conf supprimé : ".basename($oldFile);
            }
        }

        return $logLines;
    }
}
