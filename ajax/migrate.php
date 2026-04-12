<?php
/**
 * DatabaseSwitch — Moteur de migration AJAX
 * Auteur : BOUELKHEIR Yassine
 *
 * Reçoit des requêtes POST en JSON et exécute les étapes de migration.
 * Chaque action correspond à une étape du workflow affiché dans l'interface.
 *
 * Workflow complet :
 *   test_conn  → Teste les connexions source et destination
 *   step_check  → Vérifie les outils et les connexions
 *   step_backup → Sauvegarde la base source (.sql.gz)
 *   step_staging→ Crée la base staging horodatée
 *   step_migrate  → Copie les données source → staging
 *   step_defaults → Restaure les valeurs DEFAULT MySQL dans PostgreSQL (pgloader les supprime)
 *   step_seqfix   → Réinitialise les séquences / AUTO_INCREMENT
 *   step_verify   → Vérifie que 100% des lignes correspondent
 *   step_swap   → Renomme staging → production, patche conf.php
 *   step_done   → Teste la connexion Dolibarr sur la nouvelle base
 *   rollback    → Restaure conf.php depuis son backup
 */

define('NOCSRFCHECK',    1);
define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU',  1);
define('NOREQUIREHTML',  1);

ob_start();
$mainIncFound = 0;
foreach (['../../main.inc.php', '../../../main.inc.php', '../../../../main.inc.php', '../../../../../main.inc.php'] as $path) {
    if (!$mainIncFound && file_exists($path)) {
        $mainIncFound = @include $path;
        break;
    }
}
ob_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!$mainIncFound)    { respond(false, 'main.inc.php introuvable'); }
if (!$user->admin)     { respond(false, 'Accès refusé — administrateurs uniquement'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { respond(false, 'POST uniquement'); }

require_once DOL_DOCUMENT_ROOT.'/custom/databaseswitch/lib/databaseswitch.lib.php';

@set_time_limit(0);

@ini_set('memory_limit', '1G');

$requestData = json_decode(file_get_contents('php://input'), true) ?? [];
$action      = $requestData['action'] ?? '';

$migrationDirection = $requestData['direction'] ?? 'mysql2pg';

if (!in_array($migrationDirection, ['mysql2pg', 'pg2mysql'], true)) {
    respond(false, 'Direction de migration invalide');
}

function sanitizeDbName(string $name, string $default = 'dolibarr'): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    return $clean !== '' ? $clean : $default;
}

function sanitizeIdentifier(string $value, string $default = ''): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $value);
    return $clean !== '' ? $clean : $default;
}

$sourceHost     = sanitizeIdentifier($requestData['src_host']   ?? 'localhost', 'localhost');

$sourcePort     = (string)(int)($requestData['src_port']   ?? ($migrationDirection === 'pg2mysql' ? '5432' : '3306')) ?: ($migrationDirection === 'pg2mysql' ? '5432' : '3306');
$sourceDatabase = sanitizeDbName($requestData['src_db']     ?? 'dolibarr');
$sourceUser     = sanitizeIdentifier($requestData['src_user']   ?? 'root', 'root');
$sourcePassword = $requestData['src_pass']   ?? '';
$sourcePrefix   = sanitizeIdentifier($requestData['src_prefix'] ?? 'llx_', 'llx_');

$destHost       = sanitizeIdentifier($requestData['dst_host']   ?? 'localhost', 'localhost');
$destPort       = (string)(int)($requestData['dst_port']   ?? ($migrationDirection === 'mysql2pg' ? '5432' : '3306')) ?: ($migrationDirection === 'mysql2pg' ? '5432' : '3306');
$destDatabase   = sanitizeDbName($requestData['dst_db']     ?? 'dolibarr');
$destUser       = sanitizeIdentifier($requestData['dst_user']   ?? 'dolibarr_pg', 'dolibarr_pg');
$destPassword   = $requestData['dst_pass']   ?? '';
$destPrefix     = sanitizeIdentifier($requestData['dst_prefix'] ?? 'llx_', 'llx_');

$mysqlAdminUser     = sanitizeIdentifier(($requestData['mysql_admin_user'] ?? '') ?: $destUser, $destUser);
$mysqlAdminPassword = ($requestData['mysql_admin_pass'] ?? '') ?: $destPassword;

$pgAdminUser     = sanitizeIdentifier(($requestData['pg_admin_user'] ?? '') ?: '', '');
$pgAdminPassword = ($requestData['pg_admin_pass'] ?? '') ?: '';

$backupDirectory = preg_replace('/[^a-zA-Z0-9_\/.\-]/', '', $requestData['backup_dir'] ?? '/var/backups/dolibarr_migration');
$confBackupFile  = $requestData['conf_bak']   ?? null;

if ($confBackupFile !== null && (strpos($confBackupFile, '..') !== false || $confBackupFile[0] !== '/')) {
    $confBackupFile = null;
}
$confFilePath    = DOL_DOCUMENT_ROOT.'/conf/conf.php';

$stagingNameFile = $backupDirectory.'/staging_name.txt';

function respond(bool $success, string $message, array $extra = []): void
{
    $key = $success ? 'message' : 'error';

    $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
    $payload = array_merge(['success' => $success, $key => $message], $extra);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        $json = json_encode(['success' => false, 'error' => 'Erreur interne : encodage JSON échoué']);
    }
    echo $json;
    exit;
}

function readStagingDbName(string $stagingNameFile): string
{
    if (!file_exists($stagingNameFile)) return '';
    $name = trim(file_get_contents($stagingNameFile));

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) return '';
    return $name;
}

function writeStagingDbName(string $stagingNameFile, string $stagingDbName): void
{
    @mkdir(dirname($stagingNameFile), 0750, true);
    file_put_contents($stagingNameFile, $stagingDbName);
}

function connectToSourceMysql(): ?\mysqli {
    global $sourceHost, $sourcePort, $sourceDatabase, $sourceUser, $sourcePassword;
    return DatabaseSwitchLib::mysqlConnect([
        'host' => $sourceHost, 'port' => $sourcePort,
        'db'   => $sourceDatabase, 'user' => $sourceUser, 'pass' => $sourcePassword,
    ]);
}

function connectToDestMysql(string $overrideDatabase = ''): ?\mysqli {
    global $destHost, $destPort, $destDatabase, $destUser, $destPassword;
    return DatabaseSwitchLib::mysqlConnect([
        'host' => $destHost, 'port' => $destPort,
        'db'   => $overrideDatabase ?: $destDatabase, 'user' => $destUser, 'pass' => $destPassword,
    ]);
}

function connectToSourcePg(): ?\PDO {
    global $sourceHost, $sourcePort, $sourceDatabase, $sourceUser, $sourcePassword;
    return DatabaseSwitchLib::pgConnect([
        'host' => $sourceHost, 'port' => $sourcePort,
        'db'   => $sourceDatabase, 'user' => $sourceUser, 'pass' => $sourcePassword,
    ]);
}

function connectToDestPg(string $overrideDatabase = ''): ?\PDO {
    global $destHost, $destPort, $destDatabase, $destUser, $destPassword;
    return DatabaseSwitchLib::pgConnect([
        'host' => $destHost, 'port' => $destPort,
        'db'   => $overrideDatabase ?: $destDatabase, 'user' => $destUser, 'pass' => $destPassword,
    ]);
}

function connectToDestPgAsSuperuser(): ?\PDO {
    global $destHost, $destPort, $destUser, $destPassword, $pgAdminUser, $pgAdminPassword;

    if ($pgAdminUser !== '') {
        $conn = DatabaseSwitchLib::pgConnect([
            'host' => $destHost, 'port' => $destPort,
            'db'   => 'postgres', 'user' => $pgAdminUser, 'pass' => $pgAdminPassword,
        ]);
        if ($conn) return $conn;
    }

    $superConnection = DatabaseSwitchLib::pgConnectAsSuperuser($destHost, $destPort, $destUser, $destPassword);
    if ($superConnection) return $superConnection;

    return DatabaseSwitchLib::pgConnect(['host' => $destHost, 'port' => $destPort, 'db' => 'postgres', 'user' => $destUser, 'pass' => $destPassword]);
}

try {
    switch ($action) {

    case 'setup_auto':

    {
        $items       = [];
        $sshCommands = [];
        $confPath    = DOL_DOCUMENT_ROOT.'/conf/conf.php';

        $modFile = DOL_DOCUMENT_ROOT.'/custom/databaseswitch/core/modules/modDatabaseSwitch.class.php';
        $items[] = [
            'ok'    => file_exists($modFile),
            'label' => 'Module DatabaseSwitch',
            'detail' => file_exists($modFile) ? 'Installé dans htdocs/custom/' : 'Fichier descripteur introuvable',
        ];

        $pgloaderOk = DatabaseSwitchLib::commandExists('pgloader');
        $pgloaderDetail = $pgloaderOk
            ? trim(DatabaseSwitchLib::runShellCommand('pgloader --version 2>&1 | head -1')['output'])
            : 'Non installé';
        $items[] = ['ok' => $pgloaderOk, 'label' => 'pgloader', 'detail' => $pgloaderDetail];
        if (!$pgloaderOk) $sshCommands[] = 'sudo apt-get install -y pgloader';

        $psqlOk = DatabaseSwitchLib::commandExists('psql');
        $pgdumpOk = DatabaseSwitchLib::commandExists('pg_dump');
        $pgClientOk = $psqlOk && $pgdumpOk;
        $items[] = [
            'ok' => $pgClientOk,
            'label' => 'Client PostgreSQL (psql + pg_dump)',
            'detail' => $pgClientOk ? 'Disponible' : ($psqlOk ? 'pg_dump manquant' : 'Non installé'),
        ];
        if (!$pgClientOk) $sshCommands[] = 'sudo apt-get install -y postgresql-client';

        $mysqlOk = DatabaseSwitchLib::commandExists('mysql') || DatabaseSwitchLib::commandExists('mariadb');
        $mysqldumpOk = DatabaseSwitchLib::commandExists('mysqldump');
        $myClientOk = $mysqlOk && $mysqldumpOk;
        $items[] = [
            'ok' => $myClientOk,
            'label' => 'Client MariaDB (mysql + mysqldump)',
            'detail' => $myClientOk ? 'Disponible' : ($mysqlOk ? 'mysqldump manquant' : 'Non installé'),
        ];
        if (!$myClientOk) $sshCommands[] = 'sudo apt-get install -y mariadb-client';

        $backupDir = $backupDirectory ?: '/var/backups/dolibarr_migration';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }
        $backupWritable = is_writable($backupDir);

        if (!$backupWritable && function_exists('exec')) {
            $chownBin = file_exists('/usr/bin/chown') ? '/usr/bin/chown' : '/bin/chown';
            $mkdirBin = file_exists('/usr/bin/mkdir') ? '/usr/bin/mkdir' : '/bin/mkdir';
            @exec('sudo '.$mkdirBin.' -p '.escapeshellarg($backupDir).' 2>&1');
            @exec('sudo '.$chownBin.' www-data:www-data '.escapeshellarg($backupDir).' 2>&1');
            @exec('sudo chmod 750 '.escapeshellarg($backupDir).' 2>&1');
            clearstatcache(true, $backupDir);
            $backupWritable = is_writable($backupDir);
        }
        $backupFixed = false;
        if ($backupWritable && !is_dir($backupDir.'/.')) {
            $backupFixed = true;
        }
        $items[] = [
            'ok'    => $backupWritable,
            'fixed' => $backupFixed,
            'label' => 'Répertoire de sauvegarde',
            'detail' => $backupWritable
                ? $backupDir
                : 'Accès refusé — exécutez la commande SSH ci-dessous',
        ];
        if (!$backupWritable) {
            $sshCommands[] = "sudo mkdir -p {$backupDir} && sudo chown www-data:www-data {$backupDir} && sudo chmod 750 {$backupDir}";
        }

        $confWritable = is_writable($confPath);
        $confFixed = false;

        if (!$confWritable) {

            $confWritable = DatabaseSwitchLib::makeConfWritable($confPath);
            if ($confWritable) $confFixed = true;
        }
        $items[] = [
            'ok'    => $confWritable,
            'fixed' => $confFixed,
            'label' => 'Permissions conf.php',
            'detail' => $confWritable
                ? ($confFixed ? 'Corrigé automatiquement' : 'Modifiable par www-data')
                : 'Lecture seule — le swap sera impossible',
        ];
        if (!$confWritable) {
            $sshCommands[] = "sudo chown www-data:www-data {$confPath} && sudo chmod 640 {$confPath}";
        }

        $sshCommands = array_values(array_unique($sshCommands));

        respond(true, 'Vérification terminée', [
            'items'        => $items,
            'ssh_commands' => $sshCommands,
        ]);
    }

    case 'test_conn':

    {
        $side = $requestData['side'] ?? 'src';

        if ($migrationDirection === 'mysql2pg') {

            if ($side === 'src') {

                $connection = @mysqli_connect($sourceHost, $sourceUser, $sourcePassword, $sourceDatabase, (int)$sourcePort);
                if ($connection) {
                    $serverVersion = mysqli_get_server_info($connection);
                    mysqli_close($connection);
                    respond(true, "MariaDB/MySQL OK — base '{$sourceDatabase}' accessible ({$serverVersion})");
                }
                $errorMessage = mysqli_connect_error() ?: 'Connexion refusée';
                respond(false, "Connexion MariaDB échouée : {$errorMessage}. Vérifiez que le mot de passe est bien saisi dans le formulaire.");

            } else {

                $pgConnection = DatabaseSwitchLib::pgConnect([
                    'host' => $destHost, 'port' => $destPort, 'db' => 'postgres',
                    'user' => $destUser, 'pass' => $destPassword,
                ]);
                if ($pgConnection) {
                    $pgVersion = DatabaseSwitchLib::pgFetchScalar($pgConnection, 'SELECT version()');
                    respond(true, "PostgreSQL OK — utilisateur '{$destUser}' connecté ({$pgVersion})");
                }

                $pgFallback = DatabaseSwitchLib::pgConnect([
                    'host' => $destHost, 'port' => $destPort, 'db' => 'postgres',
                    'user' => 'postgres', 'pass' => '',
                ]);
                if ($pgFallback) {
                    $pgVersion = DatabaseSwitchLib::pgFetchScalar($pgFallback, 'SELECT version()');
                    respond(true, "PostgreSQL OK — superuser postgres connecté ({$pgVersion})");
                }
                respond(false,
                    "PostgreSQL inaccessible. Vérifications :\n".
                    "1. Le mot de passe destination est-il correct ?\n".
                    "2. pg_hba.conf : remplacez 'peer' par 'md5' pour les connexions host\n".
                    "3. sudo systemctl status postgresql\n".
                    "4. L'utilisateur '{$destUser}' existe-t-il ? (sudo -u postgres psql -c '\\du')"
                );
            }

        } else {

            if ($side === 'src') {
                $pgConnection = DatabaseSwitchLib::pgConnect([
                    'host' => $sourceHost, 'port' => $sourcePort, 'db' => $sourceDatabase,
                    'user' => $sourceUser, 'pass' => $sourcePassword,
                ]);
                if ($pgConnection) {
                    $pgVersion = DatabaseSwitchLib::pgFetchScalar($pgConnection, 'SELECT version()');
                    respond(true, "PostgreSQL OK — base '{$sourceDatabase}' accessible ({$pgVersion})");
                }
                respond(false, "Connexion PostgreSQL source échouée — vérifiez le mot de passe source.");

            } else {
                $connection = @mysqli_connect($destHost, $destUser, $destPassword, '', (int)$destPort);
                if ($connection) {
                    $serverVersion = mysqli_get_server_info($connection);
                    mysqli_close($connection);
                    respond(true, "MySQL/MariaDB OK — serveur accessible ({$serverVersion})");
                }
                $errorMessage = mysqli_connect_error() ?: 'Connexion refusée';
                respond(false, "MySQL/MariaDB inaccessible : {$errorMessage}");
            }
        }
    }

    case 'step_check':

    {
        $logLines = [];
        $errors   = [];

        if ($migrationDirection === 'mysql2pg') {

            if (!DatabaseSwitchLib::commandExists('pgloader')) {
                $logLines[] = 'pgloader manquant — tentative d\'installation automatique...';
                DatabaseSwitchLib::runShellCommand('apt-get install -y pgloader 2>&1');
                if (!DatabaseSwitchLib::commandExists('pgloader')) {
                    $errors[] = 'pgloader introuvable. Installez-le : sudo apt install pgloader';
                } else {
                    $result   = DatabaseSwitchLib::runShellCommand('pgloader --version');
                    $logLines[] = 'pgloader installé : '.$result['output'];
                }
            } else {
                $result   = DatabaseSwitchLib::runShellCommand('pgloader --version');
                $logLines[] = 'pgloader : '.$result['output'];
            }

            if (DatabaseSwitchLib::commandExists('psql')) {
                $result   = DatabaseSwitchLib::runShellCommand('psql --version');
                $logLines[] = 'psql : '.$result['output'];
            } else {
                $errors[] = 'psql introuvable. Installez : sudo apt install postgresql-client';
            }

            if (DatabaseSwitchLib::commandExists('mysql')) {
                $result   = DatabaseSwitchLib::runShellCommand('mysql --version');
                $logLines[] = 'mysql : '.$result['output'];
            } else {
                $errors[] = 'mysql client introuvable. Installez : sudo apt install mariadb-client';
            }

            $mysqlConnection = connectToSourceMysql();
            if ($mysqlConnection) {
                $logLines[] = 'MariaDB source OK';
                mysqli_close($mysqlConnection);
            } else {
                $errors[] = 'MariaDB source inaccessible : '.mysqli_connect_error();
            }

            $pgSuperConnection = connectToDestPgAsSuperuser();
            if ($pgSuperConnection) {
                $logLines[] = 'PostgreSQL destination OK';
            } else {
                $errors[] = "PostgreSQL inaccessible sur {$destHost}:{$destPort}";
            }

        } else {

            if (DatabaseSwitchLib::commandExists('pg_dump')) {
                $result   = DatabaseSwitchLib::runShellCommand('pg_dump --version');
                $logLines[] = 'pg_dump : '.$result['output'];
            } else {
                $errors[] = 'pg_dump introuvable. Installez : sudo apt install postgresql-client';
            }

            $pgConnection = connectToSourcePg();
            if ($pgConnection) {
                $logLines[] = 'PostgreSQL source OK';
            } else {
                $errors[] = 'PostgreSQL source inaccessible';
            }

            $mysqlConnection = @mysqli_connect($destHost, $mysqlAdminUser, $mysqlAdminPassword, '', (int)$destPort);
            if ($mysqlConnection) {
                $logLines[] = "MySQL/MariaDB destination OK (admin '{$mysqlAdminUser}')";
                mysqli_close($mysqlConnection);
            } else {
                $errors[] = "MySQL/MariaDB inaccessible avec '{$mysqlAdminUser}' : ".mysqli_connect_error()
                    ." — vérifiez les champs 'Utilisateur admin MySQL'";
            }
        }

        if (!is_dir($backupDirectory)) @mkdir($backupDirectory, 0750, true);
        if (is_writable($backupDirectory)) {
            $logLines[] = "Répertoire backups OK : {$backupDirectory}";
        } else {
            $errors[] = "Accès refusé sur {$backupDirectory} — exécutez en SSH : sudo mkdir -p {$backupDirectory} && sudo chown www-data:www-data {$backupDirectory} && sudo chmod 750 {$backupDirectory}";
        }

        if (!empty($errors)) {
            respond(false, implode(' | ', $errors), ['log' => implode("\n", $logLines)]);
        }
        respond(true, 'Tous les prérequis sont OK', ['log' => implode("\n", $logLines)]);
    }

    case 'step_backup':

    {
        @mkdir($backupDirectory, 0750, true);
        $timestamp = date('Ymd_His');
        $logLines  = [];

        if ($migrationDirection === 'mysql2pg') {
            $backupFile = $backupDirectory.'/mysql_backup_'.$timestamp.'.sql.gz';

            $command    = 'MYSQL_PWD='.escapeshellarg($sourcePassword)
                .' mysqldump'
                .' -h'.escapeshellarg($sourceHost)
                .' -P'.escapeshellarg($sourcePort)
                .' -u'.escapeshellarg($sourceUser)
                .' --single-transaction --routines --triggers --no-tablespaces'
                .' '.escapeshellarg($sourceDatabase)
                .' | gzip > '.escapeshellarg($backupFile);

            $result = DatabaseSwitchLib::runShellCommand($command);
            if (!file_exists($backupFile) || filesize($backupFile) < 10) {
                respond(false, 'mysqldump échoué', ['log' => $result['output']]);
            }
            $fileSizeKb = round(filesize($backupFile) / 1024, 1);
            $logLines[] = "Backup MySQL créé : {$backupFile} ({$fileSizeKb} KB)";

        } else {
            $backupFile = $backupDirectory.'/pg_backup_'.$timestamp.'.sql.gz';
            $command    = 'PGPASSWORD='.escapeshellarg($sourcePassword)
                .' pg_dump'
                .' -h'.escapeshellarg($sourceHost)
                .' -p'.escapeshellarg($sourcePort)
                .' -U'.escapeshellarg($sourceUser)
                .' '.escapeshellarg($sourceDatabase)
                .' | gzip > '.escapeshellarg($backupFile);

            $result = DatabaseSwitchLib::runShellCommand($command);
            if (!file_exists($backupFile) || filesize($backupFile) < 10) {
                respond(false, 'pg_dump échoué', ['log' => $result['output']]);
            }
            $fileSizeKb = round(filesize($backupFile) / 1024, 1);
            $logLines[] = "Backup PG créé : {$backupFile} ({$fileSizeKb} KB)";
        }

        respond(true, 'Sauvegarde créée avec succès', ['log' => implode("\n", $logLines)]);
    }

    case 'step_staging':

    {
        @mkdir($backupDirectory, 0750, true);
        $stagingDbName = DatabaseSwitchLib::generateStagingDbName($destDatabase);
        writeStagingDbName($stagingNameFile, $stagingDbName);
        $logLines = ["Base staging : {$stagingDbName}"];

        $cleanupBackupLog = DatabaseSwitchLib::cleanupOldBackups($backupDirectory, 5);
        $logLines = array_merge($logLines, $cleanupBackupLog);

        if ($migrationDirection === 'mysql2pg') {
            $superConnection = connectToDestPgAsSuperuser();
            if (!$superConnection) respond(false, 'Impossible de se connecter à PostgreSQL en tant que superuser');

            $cleanupLog = DatabaseSwitchLib::pgDropOldStagingDatabases($superConnection, $destDatabase);
            $logLines   = array_merge($logLines, $cleanupLog);

            if (!DatabaseSwitchLib::pgRoleExists($superConnection, $destUser)) {
                DatabaseSwitchLib::pgExecute($superConnection,
                    "CREATE USER \"{$destUser}\" WITH PASSWORD '".str_replace("'", "''", $destPassword)."'");
                $logLines[] = "Utilisateur '{$destUser}' créé";
            } else {
                $logLines[] = "Utilisateur '{$destUser}' existe déjà";
            }

            try {
                DatabaseSwitchLib::pgExecute($superConnection,
                    "CREATE DATABASE \"{$stagingDbName}\" OWNER \"{$destUser}\" ENCODING 'UTF8' TEMPLATE template0");
            } catch (\Exception $e) {

                DatabaseSwitchLib::pgExecute($superConnection,
                    "CREATE DATABASE \"{$stagingDbName}\" OWNER \"{$destUser}\" ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0");
            }
            $logLines[] = "Base staging '{$stagingDbName}' créée";

        } else {

            $mysqlConnection = @mysqli_connect($destHost, $mysqlAdminUser, $mysqlAdminPassword, '', (int)$destPort);
            if (!$mysqlConnection) {
                respond(false, "MySQL/MariaDB inaccessible avec l'admin '{$mysqlAdminUser}' : ".mysqli_connect_error()
                    ."\nVérifiez les champs 'Utilisateur admin MySQL' et son mot de passe.");
            }

            $cleanupLog = DatabaseSwitchLib::mysqlDropOldStagingDatabases($mysqlConnection, $destDatabase);
            $logLines   = array_merge($logLines, $cleanupLog);

            $created = mysqli_query($mysqlConnection,
                "CREATE DATABASE IF NOT EXISTS `{$stagingDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if (!$created) respond(false, 'Création staging MySQL échouée : '.mysqli_error($mysqlConnection));

            $escapedDestUser = mysqli_real_escape_string($mysqlConnection, $destUser);
            $hostResult = mysqli_query($mysqlConnection,
                "SELECT Host FROM mysql.user WHERE User = '{$escapedDestUser}'");
            $grantCount = 0;
            if ($hostResult) {
                while ($hostRow = mysqli_fetch_assoc($hostResult)) {
                    $h = $hostRow['Host'];
                    mysqli_query($mysqlConnection,
                        "GRANT ALL PRIVILEGES ON `{$stagingDbName}`.* TO '{$escapedDestUser}'@'{$h}'");
                    $grantCount++;
                }
            }

            if ($grantCount === 0) {
                @mysqli_query($mysqlConnection,
                    "GRANT ALL PRIVILEGES ON `{$stagingDbName}`.* TO '{$escapedDestUser}'@'localhost'");
            }
            mysqli_query($mysqlConnection, "FLUSH PRIVILEGES");
            $logLines[] = "Droits GRANT ALL accordés à '{$destUser}' sur '{$stagingDbName}' ({$grantCount} host(s))";
            $logLines[] = "Base staging '{$stagingDbName}' créée";
            mysqli_close($mysqlConnection);
        }

        respond(true, "Base staging '{$stagingDbName}' prête", ['log' => implode("\n", $logLines)]);
    }

    case 'step_migrate':

    {
        $stagingDbName = readStagingDbName($stagingNameFile);
        if (!$stagingDbName) respond(false, 'Nom de la staging introuvable — relancez l\'étape Staging');

        $logLines = ["Direction : {$migrationDirection} → staging : {$stagingDbName}"];

        if ($migrationDirection === 'mysql2pg') {

            $loadFilePath = $backupDirectory.'/migration_'.date('Ymd_His').'.load';
            $logFilePath  = $backupDirectory.'/pgloader_'.date('Ymd_His').'.log';

            $pgloaderMysqlHost = ($sourceHost === 'localhost') ? '127.0.0.1' : $sourceHost;
            $pgloaderPgHost    = ($destHost   === 'localhost') ? '127.0.0.1' : $destHost;

            $pgloaderConfig = "LOAD DATABASE\n"
                ."    FROM    mysql://{$sourceUser}:".urlencode($sourcePassword)."@{$pgloaderMysqlHost}:{$sourcePort}/{$sourceDatabase}?useSSL=false\n"

                ."    INTO    postgresql://{$destUser}:".urlencode($destPassword)."@{$pgloaderPgHost}:{$destPort}/{$stagingDbName}?sslmode=disable\n\n"
                ."WITH\n"
                ."    include drop,\n"
                ."    create tables,\n"
                ."    create indexes,\n"
                ."    reset sequences,\n"
                ."    foreign keys,\n"
                ."    downcase identifiers\n\n"
                ."SET\n"
                ."    maintenance_work_mem to '512MB',\n"
                ."    work_mem to '64MB'\n\n"
                ."CAST\n"

                ."    type bigint   when (= precision 20) to bigint   drop typemod drop default,\n"
                ."    type int      when (= precision 10) to int      drop typemod drop default,\n"
                ."    type smallint when (= precision  5) to smallint drop typemod drop default,\n"
                ."    type tinyint  to smallint  drop typemod drop default,\n"
                ."    type varchar  to text      drop typemod drop default,\n"
                ."    type char     to text      drop typemod drop default,\n"
                ."    type datetime to timestamp drop typemod drop default,\n"
                ."    type date     to date      drop typemod drop default,\n"
                ."    type double   to float8    drop typemod drop default,\n"
                ."    type float    to float     drop typemod drop default,\n"
                ."    type decimal  to numeric   drop typemod drop default,\n"
                ."    type longtext   to text    drop typemod drop default,\n"
                ."    type mediumtext to text    drop typemod drop default,\n"
                ."    type tinytext   to text    drop typemod drop default,\n"
                ."    type blob       to bytea   drop typemod drop default,\n"
                ."    type enum       to text    drop typemod drop default,\n"
                ."    type set        to text    drop typemod drop default\n\n"

                ."BEFORE LOAD DO\n"
                .'    $$ CREATE SCHEMA IF NOT EXISTS public; $$'."\n\n"
                ."AFTER LOAD DO\n"
                .'    $$ ANALYZE; $$;'."\n";

            file_put_contents($loadFilePath, $pgloaderConfig);

            @chmod($loadFilePath, 0600);

            $redactedConfig = preg_replace('/:([^@]+)@/', ':***@', $pgloaderConfig);
            $logLines[] = "=== Fichier .load généré ({$loadFilePath}) ===\n" . $redactedConfig . "\n=== Fin .load ===";

            $exitCode = 0;
            exec('pgloader --verbose '.escapeshellarg($loadFilePath).' > '.escapeshellarg($logFilePath).' 2>&1', $ignore, $exitCode);

            @unlink($loadFilePath);

            $pgloaderLog = file_exists($logFilePath) ? file_get_contents($logFilePath) : 'Log indisponible';

            @unlink($logFilePath);

            $errorLines = [];
            foreach (explode("\n", $pgloaderLog) as $line) {
                if (preg_match('/\bERROR\b|\bFATAL\b|KABOOM/i', $line)) {
                    $errorLines[] = trim($line);
                }
            }

            if ($exitCode !== 0) {
                $errorSummary = !empty($errorLines)
                    ? implode("\n", array_slice($errorLines, 0, 10))
                    : "Voir log complet ci-dessous";
                respond(false,
                    "pgloader a échoué (exit code {$exitCode}) :\n{$errorSummary}",
                    ['log' => $pgloaderLog]
                );
            }

            $totalRowsCopied = 0;
            foreach (explode("\n", $pgloaderLog) as $line) {

                if (preg_match('/Total import time\s+[✓\s]*([0-9,]+)/u', $line, $m)) {
                    $totalRowsCopied = (int)str_replace(',', '', $m[1]);
                    break;
                }
            }

            if ($totalRowsCopied === 0) {

                $sumImported = 0;
                $escapedDbForRegex  = preg_quote($sourceDatabase, '/');
                $escapedPfxForRegex = preg_quote($sourcePrefix, '/');
                foreach (explode("\n", $pgloaderLog) as $line) {
                    if (preg_match('/'.  $escapedDbForRegex . '\.' . $escapedPfxForRegex . '\S+\s+0\s+([0-9]+)\s+([0-9]+)/', $line, $m)) {
                        $sumImported += (int)$m[2];
                    }
                }
                $totalRowsCopied = $sumImported;
            }

            if ($totalRowsCopied === 0) {
                $errorSummary = !empty($errorLines)
                    ? "Erreurs détectées :\n".implode("\n", array_slice($errorLines, 0, 15))
                    : "Aucune ligne ERROR trouvée — vérifiez le log complet.";
                respond(false,
                    "pgloader terminé (exit 0) mais 0 ligne copiée.\n\n"
                    ."Causes possibles :\n"
                    ."1. Mot de passe source incorrect dans l'URL pgloader\n"
                    ."2. Utilisateur MySQL sans SELECT sur '$sourceDatabase'\n"
                    ."3. Erreur dans BEFORE LOAD DO (voir log)\n\n"
                    ."{$errorSummary}",
                    ['log' => $pgloaderLog]
                );
            }

            $logLines[] = "pgloader : {$totalRowsCopied} lignes copiées au total";

            if (!empty($errorLines)) {
                $logLines[] = "Avertissements pgloader (non bloquants) :";
                $logLines   = array_merge($logLines, array_slice($errorLines, 0, 10));
            }

        } else {

            $pgSourceConnection = connectToSourcePg();
            if (!$pgSourceConnection) respond(false, 'Connexion PostgreSQL source échouée');

            $mysqlStagingConnection = @mysqli_connect($destHost, $mysqlAdminUser, $mysqlAdminPassword, $stagingDbName, (int)$destPort);
            if (!$mysqlStagingConnection) {

                $mysqlStagingConnection = @mysqli_connect($destHost, $destUser, $destPassword, $stagingDbName, (int)$destPort);
                if (!$mysqlStagingConnection) {
                    respond(false,
                        "Connexion MySQL staging échouée pour les deux utilisateurs.\n"
                        ."Admin ({$mysqlAdminUser}) : ".mysqli_connect_error()."\n"
                        ."Solution : GRANT ALL ON `{$stagingDbName}`.* TO '{$destUser}'@'localhost';"
                    );
                }
            }

            mysqli_set_charset($mysqlStagingConnection, 'utf8mb4');
            mysqli_query($mysqlStagingConnection, 'SET FOREIGN_KEY_CHECKS = 0');
            mysqli_query($mysqlStagingConnection, 'SET UNIQUE_CHECKS = 0');
            mysqli_query($mysqlStagingConnection, 'SET sql_mode = ""');

            $dbNameCheck = DatabaseSwitchLib::pgFetchScalar($pgSourceConnection, 'SELECT current_database()');
            $schemaCheck = DatabaseSwitchLib::pgFetchScalar($pgSourceConnection,
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema','pg_toast') AND table_type='BASE TABLE'");
            $prefixCheck = DatabaseSwitchLib::pgFetchScalar($pgSourceConnection,
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema','pg_toast') AND table_type='BASE TABLE' AND table_name LIKE '".addslashes($sourcePrefix)."%'");
            $logLines[] = "DEBUG connexion PG : base='{$dbNameCheck}', total tables public={$schemaCheck}, tables {$sourcePrefix}*={$prefixCheck}";
            $logLines[] = "DEBUG params : src_host={$sourceHost} src_port={$sourcePort} src_db={$sourceDatabase} src_user={$sourceUser} src_prefix='{$sourcePrefix}'";

            $sampleTables = $pgSourceConnection->query(
                "SELECT schemaname||'.' ||tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema') LIMIT 5"
            )->fetchAll(PDO::FETCH_COLUMN);
            $logLines[] = "DEBUG premières tables : ".(!empty($sampleTables) ? implode(', ', $sampleTables) : '(aucune)');

            $pgSchema = $pgSourceConnection->query(
                "SELECT table_schema FROM information_schema.tables
                 WHERE table_schema NOT IN ('pg_catalog','information_schema','pg_toast')
                   AND table_type = 'BASE TABLE'
                   AND table_name LIKE '".addslashes($sourcePrefix)."%'
                 LIMIT 1"
            )->fetchColumn();
            $pgSchema = $pgSchema ?: 'public';
            $logLines[] = "Schéma PostgreSQL source détecté : '{$pgSchema}'";

            $tableNames = DatabaseSwitchLib::pgGetTableNames($pgSourceConnection, $sourcePrefix);
            $logLines[] = count($tableNames).' tables à copier';

            foreach ($tableNames as $tableName) {
                $logLines[] = "  → {$tableName}";

                $columnsInfo = $pgSourceConnection->query(
                    "SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
                     FROM information_schema.columns
                     WHERE table_schema = '".addslashes($pgSchema)."'
                       AND table_name = '".addslashes($tableName)."'
                     ORDER BY ordinal_position"
                )->fetchAll(PDO::FETCH_ASSOC);

                if (empty($columnsInfo)) continue;

                $columnDefinitions = [];
                $primaryKeyColumn  = null;

                foreach ($columnsInfo as $column) {
                    $columnName  = '`'.$column['column_name'].'`';
                    $mysqlType   = pgTypeToMysqlType($column['data_type'], (int)($column['character_maximum_length'] ?? 255));
                    $nullability = ($column['is_nullable'] === 'YES') ? 'NULL' : 'NOT NULL';
                    $autoInc     = '';
                    $defaultClause = '';

                    if ($column['column_default'] && strpos($column['column_default'], 'nextval') !== false) {
                        $autoInc          = 'AUTO_INCREMENT';
                        $nullability      = 'NOT NULL';
                        $primaryKeyColumn = $column['column_name'];
                        $mysqlType        = 'BIGINT';
                    } elseif ($column['column_default'] !== null && $column['column_default'] !== '') {

                        $pgDefault = $column['column_default'];

                        $pgDefault = preg_replace('/::[a-zA-Z0-9_ ]+(\[\])?/', '', $pgDefault);
                        $pgDefault = trim($pgDefault);

                        if (strtolower($pgDefault) === 'now()' || strtolower($pgDefault) === 'current_timestamp') {

                            $defaultClause = "DEFAULT CURRENT_TIMESTAMP";
                        } elseif (strtolower($pgDefault) === 'current_date') {
                            $defaultClause = "DEFAULT (CURRENT_DATE)";
                        } elseif (strtolower($pgDefault) === 'true') {
                            $defaultClause = "DEFAULT 1";
                        } elseif (strtolower($pgDefault) === 'false') {
                            $defaultClause = "DEFAULT 0";
                        } elseif (strtolower($pgDefault) === 'null') {
                            $defaultClause = "DEFAULT NULL";
                        } elseif (preg_match('/^-?\d+(\.\d+)?$/', $pgDefault)) {

                            $defaultClause = "DEFAULT {$pgDefault}";
                        } elseif (preg_match("/^'(.*)'$/s", $pgDefault, $m)) {

                            $inner = str_replace("''", "'", $m[1]);
                            $inner = addslashes($inner);
                            $defaultClause = "DEFAULT '{$inner}'";
                        }

                    }

                    $columnDefinitions[] = trim("{$columnName} {$mysqlType} {$nullability} {$defaultClause} {$autoInc}");
                }
                if ($primaryKeyColumn) {
                    $columnDefinitions[] = "PRIMARY KEY (`{$primaryKeyColumn}`)";
                }

                $createTableSql = "CREATE TABLE IF NOT EXISTS `{$tableName}` ("
                    .implode(', ', $columnDefinitions)
                    .") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

                mysqli_query($mysqlStagingConnection, "DROP TABLE IF EXISTS `{$tableName}`");
                if (!mysqli_query($mysqlStagingConnection, $createTableSql)) {
                    $logLines[] = "  ⚠ CREATE TABLE échoué : ".mysqli_error($mysqlStagingConnection);
                    continue;
                }

                $columnNames = array_column($columnsInfo, 'column_name');
                $totalRows   = (int)($pgSourceConnection->query(
                    "SELECT COUNT(*) FROM \"{$pgSchema}\".\"{$tableName}\""
                )->fetchColumn() ?? 0);
                $batchSize   = 500;
                $offset      = 0;

                while ($offset < $totalRows) {
                    $rows = $pgSourceConnection->query(
                        "SELECT * FROM \"{$pgSchema}\".\"{$tableName}\" ORDER BY 1 LIMIT {$batchSize} OFFSET {$offset}"
                    )->fetchAll(PDO::FETCH_NUM);

                    if (empty($rows)) break;

                    $valueSets = [];
                    foreach ($rows as $row) {
                        $escapedValues = array_map(function ($value) use ($mysqlStagingConnection) {
                            if ($value === null) return 'NULL';
                            if ($value === 't')  return '1';
                            if ($value === 'f')  return '0';
                            return "'".mysqli_real_escape_string($mysqlStagingConnection, (string)$value)."'";
                        }, $row);
                        $valueSets[] = '('.implode(', ', $escapedValues).')';
                    }

                    $columnList = '`'.implode('`, `', $columnNames).'`';
                    $insertSql  = "INSERT IGNORE INTO `{$tableName}` ({$columnList}) VALUES ".implode(', ', $valueSets);

                    if (!mysqli_query($mysqlStagingConnection, $insertSql)) {
                        $logLines[] = "  ⚠ INSERT lot {$offset} échoué : ".mysqli_error($mysqlStagingConnection);
                    }
                    $offset += $batchSize;
                }
            }

            mysqli_query($mysqlStagingConnection, 'SET FOREIGN_KEY_CHECKS = 1');
            mysqli_query($mysqlStagingConnection, 'SET UNIQUE_CHECKS = 1');
            mysqli_close($mysqlStagingConnection);
            $logLines[] = 'Copie terminée';
        }

        respond(true, 'Migration vers staging terminée', ['log' => implode("\n", $logLines)]);
    }

    case 'step_defaults':

    {
        if ($migrationDirection !== 'mysql2pg') {

            $stagingDbName = readStagingDbName($stagingNameFile);
            if (!$stagingDbName) respond(false, 'Nom de la staging introuvable — relancez l\'étape Staging');

            $mysqlStagingConnection = connectToDestMysql($stagingDbName);
            if (!$mysqlStagingConnection) respond(false, 'Connexion MySQL staging échouée');

            $countResult = mysqli_query($mysqlStagingConnection,
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$stagingDbName}'
                 AND COLUMN_DEFAULT IS NOT NULL
                 AND EXTRA NOT LIKE '%auto_increment%'"
            );
            $countRow      = $countResult ? mysqli_fetch_row($countResult) : null;
            $defaultsCount = $countRow ? (int)$countRow[0] : 0;

            $tableResult = mysqli_query($mysqlStagingConnection,
                "SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$stagingDbName}'
                 AND COLUMN_DEFAULT IS NOT NULL
                 AND EXTRA NOT LIKE '%auto_increment%'"
            );
            $tableRow    = $tableResult ? mysqli_fetch_row($tableResult) : null;
            $tablesCount = $tableRow ? (int)$tableRow[0] : 0;

            mysqli_close($mysqlStagingConnection);

            $logLines = [
                "Direction pg→mysql : DEFAULT intégrés dans step_migrate via information_schema.columns",
                "✅ {$defaultsCount} colonnes avec DEFAULT réparties sur {$tablesCount} tables",
            ];
            respond(true, "DEFAULT confirmés : {$defaultsCount} colonnes sur {$tablesCount} tables", ['log' => implode("\n", $logLines)]);
        }

        $stagingDbName = readStagingDbName($stagingNameFile);
        if (!$stagingDbName) respond(false, 'Nom de la staging introuvable — relancez l\'étape Staging');

        $mysqlSourceConnection = connectToSourceMysql();
        if (!$mysqlSourceConnection) respond(false, 'Connexion MySQL source échouée');

        $pgStagingConnection = DatabaseSwitchLib::pgConnect([
            'host' => $destHost, 'port' => $destPort, 'db' => $stagingDbName,
            'user' => $destUser,  'pass' => $destPassword,
        ]);
        if (!$pgStagingConnection) respond(false, 'Connexion PG staging échouée');

        $pgSchema = DatabaseSwitchLib::pgFetchScalar($pgStagingConnection,
            "SELECT table_schema FROM information_schema.tables
             WHERE table_schema NOT IN ('pg_catalog','information_schema','pg_toast')
               AND table_type = 'BASE TABLE'
               AND table_name LIKE '".addslashes($sourcePrefix)."%'
             LIMIT 1"
        ) ?? 'public';

        $logLines    = ["Schéma PG cible : '{$pgSchema}'"];
        $applyCount  = 0;
        $skipCount   = 0;
        $errorCount  = 0;

        $escapedDb     = mysqli_real_escape_string($mysqlSourceConnection, $sourceDatabase);
        $escapedPrefix = mysqli_real_escape_string($mysqlSourceConnection, $sourcePrefix);

        $mysqlColumns = DatabaseSwitchLib::mysqlFetchAll($mysqlSourceConnection,
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_DEFAULT, DATA_TYPE, EXTRA, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = '{$escapedDb}'
               AND TABLE_NAME LIKE '{$escapedPrefix}%'
             ORDER BY TABLE_NAME, ORDINAL_POSITION"
        );

        $convertDefault = function(string $mysqlDefault, string $dataType): ?string {

            $lower = strtolower(trim($mysqlDefault));

            if ($lower === 'null') return 'NULL';

            if ($dataType === 'tinyint') {
                if (is_numeric($mysqlDefault)) return (string)(int)$mysqlDefault;
            }

            $exactNumericTypes = ['int','bigint','smallint','mediumint','decimal','numeric','float','double','real','year'];
            if (in_array($dataType, $exactNumericTypes, true)) {
                if (is_numeric($mysqlDefault)) return $mysqlDefault;
            }

            if (in_array($lower, ['current_timestamp','current_timestamp()','now()'], true)
                || strpos($lower, 'current_timestamp') === 0) {
                return 'now()';
            }

            if ($lower === 'current_date') return 'CURRENT_DATE';
            if ($lower === 'current_time') return 'CURRENT_TIME';

            if ($mysqlDefault === '0000-00-00' || $mysqlDefault === '0000-00-00 00:00:00') return 'NULL';

            if (strpos($mysqlDefault, 'nextval(') === 0) return null;

            $escaped = str_replace("'", "''", $mysqlDefault);
            return "'{$escaped}'";
        };

        $currentTable  = null;
        $tableLogLines = [];

        foreach ($mysqlColumns as $col) {
            $tableName  = $col['TABLE_NAME'];
            $columnName = $col['COLUMN_NAME'];
            $rawDefault = $col['COLUMN_DEFAULT'];
            $dataType   = strtolower($col['DATA_TYPE']);
            $extra      = strtolower($col['EXTRA'] ?? '');

            if (strpos($extra, 'auto_increment') !== false) {
                $skipCount++;
                continue;
            }

            if ($rawDefault === null) {
                $skipCount++;
                continue;
            }

            $pgDefault = $convertDefault($rawDefault, $dataType);
            if ($pgDefault === null) {
                $skipCount++;
                continue;
            }

            $safeTable  = strtolower($tableName);
            $safeColumn = strtolower($columnName);

            $sql = "ALTER TABLE \"{$pgSchema}\".\"{$safeTable}\" ALTER COLUMN \"{$safeColumn}\" SET DEFAULT {$pgDefault}";

            try {
                DatabaseSwitchLib::pgExecute($pgStagingConnection, $sql);
                if ($currentTable !== $tableName) {
                    if ($currentTable !== null && !empty($tableLogLines)) {
                        $logLines[] = "  → {$currentTable} : ".implode(', ', $tableLogLines);
                    }
                    $currentTable  = $tableName;
                    $tableLogLines = [];
                }
                $tableLogLines[] = "{$safeColumn}={$pgDefault}";
                $applyCount++;
            } catch (\Exception $e) {

                $logLines[] = "  ⚠ {$safeTable}.{$safeColumn} : ".$e->getMessage();
                $errorCount++;
            }
        }

        if ($currentTable !== null && !empty($tableLogLines)) {
            $logLines[] = "  → {$currentTable} : ".implode(', ', $tableLogLines);
        }

        mysqli_close($mysqlSourceConnection);

        $logLines[] = "Résultat : {$applyCount} DEFAULT appliqués, {$skipCount} ignorés (AUTO_INCREMENT/NULL), {$errorCount} erreur(s)";

        respond(true, "{$applyCount} valeurs DEFAULT restaurées dans la staging PostgreSQL", ['log' => implode("\n", $logLines)]);
    }

    case 'step_seqfix':

    {
        $stagingDbName = readStagingDbName($stagingNameFile);
        if (!$stagingDbName) respond(false, 'Nom de la staging introuvable — relancez l\'étape Staging');
        $logLines      = [];

        if ($migrationDirection === 'mysql2pg') {

            $pgStagingConnection = DatabaseSwitchLib::pgConnect([
                'host' => $destHost, 'port' => $destPort, 'db' => $stagingDbName,
                'user' => $destUser,  'pass' => $destPassword,
            ]);
            if (!$pgStagingConnection) respond(false, 'Connexion PG staging échouée');

            $result   = DatabaseSwitchLib::pgResetAllSequences($pgStagingConnection, $destPrefix);
            $logLines = $result['logLines'];
            respond(true, $result['count'].' séquences réinitialisées', ['log' => implode("\n", $logLines)]);

        } else {

            $mysqlStagingConnection = @mysqli_connect($destHost, $mysqlAdminUser, $mysqlAdminPassword, $stagingDbName, (int)$destPort);
            if (!$mysqlStagingConnection) {

                $mysqlStagingConnection = @mysqli_connect($destHost, $destUser, $destPassword, $stagingDbName, (int)$destPort);
            }
            if (!$mysqlStagingConnection) respond(false, 'Connexion MySQL staging échouée — ni admin ni destUser n\'ont accès à '.$stagingDbName);

            $tableNames   = DatabaseSwitchLib::mysqlGetTableNames($mysqlStagingConnection, $stagingDbName, $destPrefix);
            $resetCount   = 0;

            foreach ($tableNames as $tableName) {
                $autoIncColumns = DatabaseSwitchLib::mysqlFetchAll($mysqlStagingConnection,
                    "SELECT COLUMN_NAME
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = '{$stagingDbName}'
                       AND TABLE_NAME = '{$tableName}'
                       AND EXTRA = 'auto_increment'"
                );
                if (empty($autoIncColumns)) continue;

                $columnName  = $autoIncColumns[0]['COLUMN_NAME'];
                $currentMax  = (int)(DatabaseSwitchLib::mysqlFetchScalar($mysqlStagingConnection,
                    "SELECT COALESCE(MAX(`{$columnName}`), 0) FROM `{$tableName}`") ?? 0);
                $nextValue   = $currentMax + 1;

                mysqli_query($mysqlStagingConnection, "ALTER TABLE `{$tableName}` AUTO_INCREMENT = {$nextValue}");
                $logLines[] = "  ↺ {$tableName}.{$columnName} → prochain = {$nextValue}";
                $resetCount++;
            }

            mysqli_close($mysqlStagingConnection);
            respond(true, "{$resetCount} AUTO_INCREMENT réinitialisés", ['log' => implode("\n", $logLines)]);
        }
    }

    case 'step_verify':

    {
        $stagingDbName = readStagingDbName($stagingNameFile);
        if (!$stagingDbName) respond(false, 'Nom de la staging introuvable — relancez l\'étape Staging');
        $logLines      = [];
        $mismatches    = [];
        $totalSource   = 0;
        $totalStaging  = 0;

        if ($migrationDirection === 'mysql2pg') {
            $mysqlSourceConnection = connectToSourceMysql();
            if (!$mysqlSourceConnection) respond(false, 'Connexion MySQL source échouée');

            $pgStagingConnection = DatabaseSwitchLib::pgConnect([
                'host' => $destHost, 'port' => $destPort, 'db' => $stagingDbName,
                'user' => $destUser,  'pass' => $destPassword,
            ]);
            if (!$pgStagingConnection) respond(false, 'Connexion PG staging échouée');

            $tableNames = DatabaseSwitchLib::mysqlGetTableNames($mysqlSourceConnection, $sourceDatabase, $sourcePrefix);
            $logLines[] = count($tableNames).' tables à vérifier';

            foreach ($tableNames as $tableName) {
                $sourceCount  = DatabaseSwitchLib::mysqlCountRows($mysqlSourceConnection, $tableName);
                $stagingCount = DatabaseSwitchLib::pgCountRows($pgStagingConnection, $tableName);
                $matches      = ($sourceCount === $stagingCount);
                $totalSource  += $sourceCount;
                $totalStaging += $stagingCount;
                $logLines[]   = ($matches ? '  ✓' : '  ✗')." {$tableName} : source={$sourceCount} staging={$stagingCount}";
                if (!$matches) $mismatches[] = "{$tableName} (source={$sourceCount} staging={$stagingCount})";
            }
            mysqli_close($mysqlSourceConnection);

        } else {
            $pgSourceConnection  = connectToSourcePg();
            if (!$pgSourceConnection) respond(false, 'Connexion PG source échouée');

            $mysqlStagingConnection = @mysqli_connect($destHost, $mysqlAdminUser, $mysqlAdminPassword, $stagingDbName, (int)$destPort);
            if (!$mysqlStagingConnection) {
                $mysqlStagingConnection = @mysqli_connect($destHost, $destUser, $destPassword, $stagingDbName, (int)$destPort);
            }
            if (!$mysqlStagingConnection) respond(false, 'Connexion MySQL staging échouée');

            $tableNames = DatabaseSwitchLib::pgGetTableNames($pgSourceConnection, $sourcePrefix);
            $logLines[] = count($tableNames).' tables à vérifier';

            foreach ($tableNames as $tableName) {
                $sourceCount  = DatabaseSwitchLib::pgCountRows($pgSourceConnection, $tableName);
                $stagingCount = DatabaseSwitchLib::mysqlCountRows($mysqlStagingConnection, $tableName);
                $matches      = ($sourceCount === $stagingCount);
                $totalSource  += $sourceCount;
                $totalStaging += $stagingCount;
                $logLines[]   = ($matches ? '  ✓' : '  ✗')." {$tableName} : source={$sourceCount} staging={$stagingCount}";
                if (!$matches) $mismatches[] = "{$tableName} (source={$sourceCount} staging={$stagingCount})";
            }
            mysqli_close($mysqlStagingConnection);
        }

        $logLines[] = "Total — source : {$totalSource} lignes | staging : {$totalStaging} lignes";

        if (!empty($mismatches)) {
            $mismatchCount = count($mismatches);
            $mismatchList  = implode(', ', array_slice($mismatches, 0, 5));
            respond(false,
                "{$mismatchCount} table(s) avec des différences : {$mismatchList}".($mismatchCount > 5 ? '...' : ''),
                ['log' => implode("\n", $logLines)]
            );
        }

        respond(true, "✅ 100% des lignes correspondent ({$totalSource} lignes)", ['log' => implode("\n", $logLines)]);
    }

    case 'step_swap':

    {
        $stagingDbName = readStagingDbName($stagingNameFile);
        if (!$stagingDbName) respond(false, 'Nom de la staging introuvable — relancez l\'étape Staging');
        $oldDbName     = $destDatabase.'_old_'.date('Ymd_His');
        $logLines      = [];

        if ($migrationDirection === 'mysql2pg') {

            if (!file_exists($confFilePath)) {
                respond(false, "conf.php introuvable : {$confFilePath}");
            }
            if (!DatabaseSwitchLib::makeConfWritable($confFilePath)) {
                respond(false,
                    "⛔ conf.php non modifiable — swap annulé, aucune base modifiée.\n"
                    ."Corrigez les permissions AVANT de relancer :\n"
                    ."  sudo chown www-data:www-data {$confFilePath}\n"
                    ."  sudo chmod 640 {$confFilePath}"
                );
            }
            $logLines[] = "conf.php modifiable ✓ — démarrage du swap";

            $superConnection = connectToDestPgAsSuperuser();
            if (!$superConnection) respond(false, 'Impossible d\'accéder à PostgreSQL en tant que superuser pour le swap');

            $stagingConn = connectToDestPg($stagingDbName);
            if ($stagingConn) {

                $stagingTableCount = DatabaseSwitchLib::pgFetchScalar($stagingConn,
                    "SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema NOT IN ('pg_catalog','information_schema','pg_toast')
                       AND table_type = 'BASE TABLE'"
                );
                $stagingConn = null;
                if ((int)$stagingTableCount === 0) {
                    respond(false,
                        "⛔ Swap annulé — la staging '{$stagingDbName}' est vide (0 tables).\n"
                        ."Relancez step_migrate d'abord."
                    );
                }
                $logLines[] = "Staging contient {$stagingTableCount} tables ✓";
            }

            if (DatabaseSwitchLib::pgDatabaseExists($superConnection, $destDatabase)) {
                try {
                    $superConnection->exec(
                        "SELECT pg_terminate_backend(pid)
                         FROM pg_stat_activity
                         WHERE datname = '{$destDatabase}' AND pid <> pg_backend_pid()"
                    );
                    $superConnection->exec("ALTER DATABASE \"{$destDatabase}\" RENAME TO \"{$oldDbName}\"");
                    $logLines[] = "Ancienne base '{$destDatabase}' renommée en '{$oldDbName}'";
                } catch (\Exception $e) {
                    $logLines[] = "⚠ Impossible de renommer l'ancienne base : ".$e->getMessage();
                }
            }

            try {
                $superConnection->exec(
                    "SELECT pg_terminate_backend(pid)
                     FROM pg_stat_activity
                     WHERE datname = '{$stagingDbName}' AND pid <> pg_backend_pid()"
                );
                $superConnection->exec("ALTER DATABASE \"{$stagingDbName}\" RENAME TO \"{$destDatabase}\"");
                $logLines[] = "Staging '{$stagingDbName}' renommée en '{$destDatabase}' ✓";
            } catch (\Exception $e) {

                if (DatabaseSwitchLib::pgDatabaseExists($superConnection, $oldDbName)) {
                    try { $superConnection->exec("ALTER DATABASE \"{$oldDbName}\" RENAME TO \"{$destDatabase}\""); } catch (\Exception $e2) {}
                }
                respond(false, 'Swap échoué : '.$e->getMessage(), ['log' => implode("\n", $logLines)]);
            }

            $newConfValues = [
                'dolibarr_main_db_type' => 'pgsql',
                'dolibarr_main_db_host' => $destHost,
                'dolibarr_main_db_port' => $destPort,
                'dolibarr_main_db_name' => $destDatabase,
                'dolibarr_main_db_user' => $destUser,
                'dolibarr_main_db_pass' => $destPassword,
            ];
            $backupFilePath = DatabaseSwitchLib::patchDolibarrConf($confFilePath, $newConfValues, $backupDirectory);
            $logLines[] = "conf.php mis à jour — backup : {$backupFilePath}";

            if (DatabaseSwitchLib::pgDatabaseExists($superConnection, $oldDbName)) {
                try {
                    $superConnection->exec("DROP DATABASE \"{$oldDbName}\"");
                    $logLines[] = "Ancienne base '{$oldDbName}' supprimée";
                } catch (\Exception $e) {
                    $logLines[] = "⚠ Impossible de supprimer '{$oldDbName}' — supprimez-la manuellement si besoin";
                }
            }

            @unlink($stagingNameFile);
            respond(true, 'Swap terminé — Dolibarr pointe maintenant sur PostgreSQL',
                ['log' => implode("\n", $logLines), 'conf_bak' => $backupFilePath]);

        } else {

            if (!file_exists($confFilePath)) {
                respond(false, "conf.php introuvable : {$confFilePath}");
            }
            if (!DatabaseSwitchLib::makeConfWritable($confFilePath)) {
                respond(false,
                    "⛔ conf.php non modifiable — swap annulé, aucune base modifiée.\n"
                    ."Corrigez les permissions AVANT de relancer :\n"
                    ."  sudo chown www-data:www-data {$confFilePath}\n"
                    ."  sudo chmod 640 {$confFilePath}"
                );
            }
            $logLines[] = "conf.php modifiable ✓ — démarrage du swap";

            $mysqlConnection = @mysqli_connect($destHost, $mysqlAdminUser, $mysqlAdminPassword, '', (int)$destPort);
            if (!$mysqlConnection) respond(false, 'Connexion MySQL admin échouée pour le swap : '.mysqli_connect_error());

            $stagingDbNameClean = trim($stagingDbName);
            $logLines[] = "DEBUG staging name: '".addslashes($stagingDbNameClean)."' (len=".strlen($stagingDbNameClean).")";

            $stagingCheckResult = mysqli_query($mysqlConnection, "SHOW TABLES FROM `{$stagingDbNameClean}`");
            if (!$stagingCheckResult) {

                $dbListResult = mysqli_query($mysqlConnection, "SHOW DATABASES LIKE 'dolibarr%'");
                $dbList = [];
                while ($row = mysqli_fetch_row($dbListResult)) $dbList[] = $row[0];
                respond(false,
                    "⛔ Swap annulé — staging '{$stagingDbNameClean}' inaccessible : ".mysqli_error($mysqlConnection)."\n"
                    ."Bases dolibarr* disponibles : ".implode(', ', $dbList)
                );
            }
            $stagingTableCount = mysqli_num_rows($stagingCheckResult);
            $logLines[] = "Staging '{$stagingDbNameClean}' contient {$stagingTableCount} tables";
            if ($stagingTableCount === 0) {
                $dbListResult = mysqli_query($mysqlConnection, "SHOW DATABASES LIKE 'dolibarr%'");
                $dbList = [];
                while ($row = mysqli_fetch_row($dbListResult)) $dbList[] = $row[0];
                respond(false,
                    "⛔ Swap annulé — la staging '{$stagingDbNameClean}' est vide (0 tables).\n"
                    ."Bases dolibarr* disponibles : ".implode(', ', $dbList)."\n"
                    ."Relancez step_migrate d'abord."
                );
            }
            $logLines[] = "Staging contient {$stagingTableCount} tables ✓";

            mysqli_query($mysqlConnection, 'SET FOREIGN_KEY_CHECKS = 0');
            mysqli_query($mysqlConnection, 'SET SESSION sql_mode = ""');

            $fkDefinitions = [];
            $fkResult = mysqli_query($mysqlConnection,
                "SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                        kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                        rc.UPDATE_RULE, rc.DELETE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE kcu
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                   ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                  AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
                 WHERE kcu.TABLE_SCHEMA = '{$stagingDbName}'
                   AND kcu.REFERENCED_TABLE_NAME IS NOT NULL"
            );
            if ($fkResult) {
                while ($row = mysqli_fetch_assoc($fkResult)) {
                    $fkDefinitions[] = $row;
                }
            }
            $logLines[] = count($fkDefinitions).' contraintes FK collectées depuis la staging';

            $fkDropped = [];
            foreach ($fkDefinitions as $fk) {
                $tbl  = $fk['TABLE_NAME'];
                $name = $fk['CONSTRAINT_NAME'];
                if (!isset($fkDropped["{$tbl}.{$name}"])) {
                    mysqli_query($mysqlConnection,
                        "ALTER TABLE `{$stagingDbName}`.`{$tbl}` DROP FOREIGN KEY `{$name}`");
                    $fkDropped["{$tbl}.{$name}"] = true;
                }
            }
            $logLines[] = count($fkDropped).' FK supprimées de la staging (seront recréées après)';

            $productionExists = DatabaseSwitchLib::mysqlFetchScalar($mysqlConnection,
                "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$destDatabase}'"
            );

            if ($productionExists) {
                mysqli_query($mysqlConnection, "CREATE DATABASE IF NOT EXISTS `{$oldDbName}` CHARACTER SET utf8mb4");
                $tablesToMove = DatabaseSwitchLib::mysqlGetTableNames($mysqlConnection, $destDatabase, $destPrefix);

                $oldFkResult = mysqli_query($mysqlConnection,
                    "SELECT TABLE_NAME, CONSTRAINT_NAME
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = '{$destDatabase}'
                       AND REFERENCED_TABLE_NAME IS NOT NULL"
                );
                if ($oldFkResult) {
                    $oldFkDropped = [];
                    while ($row = mysqli_fetch_assoc($oldFkResult)) {
                        $key = $row['TABLE_NAME'].'.'.$row['CONSTRAINT_NAME'];
                        if (!isset($oldFkDropped[$key])) {
                            mysqli_query($mysqlConnection,
                                "ALTER TABLE `{$destDatabase}`.`{$row['TABLE_NAME']}` DROP FOREIGN KEY `{$row['CONSTRAINT_NAME']}`");
                            $oldFkDropped[$key] = true;
                        }
                    }
                }

                foreach ($tablesToMove as $tableName) {
                    $renamed = mysqli_query($mysqlConnection,
                        "RENAME TABLE `{$destDatabase}`.`{$tableName}` TO `{$oldDbName}`.`{$tableName}`");
                    if (!$renamed) {
                        $logLines[] = "⚠ RENAME échoué pour {$tableName} : ".mysqli_error($mysqlConnection)." — copie manuelle";
                        mysqli_query($mysqlConnection, "CREATE TABLE `{$oldDbName}`.`{$tableName}` LIKE `{$destDatabase}`.`{$tableName}`");
                        mysqli_query($mysqlConnection, "INSERT INTO `{$oldDbName}`.`{$tableName}` SELECT * FROM `{$destDatabase}`.`{$tableName}`");
                        mysqli_query($mysqlConnection, "DROP TABLE IF EXISTS `{$destDatabase}`.`{$tableName}`");
                    }
                }
                mysqli_query($mysqlConnection, "DROP DATABASE IF EXISTS `{$destDatabase}`");
                $logLines[] = "Ancienne base '{$destDatabase}' sauvegardée dans '{$oldDbName}'";
            }

            mysqli_query($mysqlConnection,
                "CREATE DATABASE IF NOT EXISTS `{$destDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $tablesToTransfer = DatabaseSwitchLib::mysqlGetTableNames($mysqlConnection, $stagingDbName, $destPrefix);
            $renameErrors = [];
            foreach ($tablesToTransfer as $tableName) {
                $ok = mysqli_query($mysqlConnection,
                    "RENAME TABLE `{$stagingDbName}`.`{$tableName}` TO `{$destDatabase}`.`{$tableName}`");
                if (!$ok) {
                    $renameErrors[] = "{$tableName} : ".mysqli_error($mysqlConnection);
                }
            }

            if (!empty($renameErrors)) {

                mysqli_query($mysqlConnection, 'SET FOREIGN_KEY_CHECKS = 1');
                respond(false,
                    'RENAME TABLE échoué pour '.count($renameErrors).' table(s). conf.php non modifiée — Dolibarr fonctionne toujours.',
                    ['log' => implode("
", array_merge($logLines, $renameErrors))]
                );
            }

            mysqli_query($mysqlConnection, "DROP DATABASE IF EXISTS `{$stagingDbName}`");
            $logLines[] = "Staging '{$stagingDbName}' → '{$destDatabase}' (".count($tablesToTransfer)." tables) ✓";

            $fkRestored = 0;
            $fkFailed   = [];
            foreach ($fkDefinitions as $fk) {
                $sql = "ALTER TABLE `{$destDatabase}`.`{$fk['TABLE_NAME']}` "
                     . "ADD CONSTRAINT `{$fk['CONSTRAINT_NAME']}` "
                     . "FOREIGN KEY (`{$fk['COLUMN_NAME']}`) "
                     . "REFERENCES `{$destDatabase}`.`{$fk['REFERENCED_TABLE_NAME']}` "
                     . "(`{$fk['REFERENCED_COLUMN_NAME']}`) "
                     . "ON UPDATE {$fk['UPDATE_RULE']} ON DELETE {$fk['DELETE_RULE']}";
                $ok = mysqli_query($mysqlConnection, $sql);
                if ($ok) {
                    $fkRestored++;
                } else {
                    $fkFailed[] = "{$fk['TABLE_NAME']}.{$fk['CONSTRAINT_NAME']} : ".mysqli_error($mysqlConnection);
                }
            }
            $logLines[] = "{$fkRestored} FK recréées".(count($fkFailed) ? ' ('.count($fkFailed).' échecs — non bloquant)' : ' ✓');

            mysqli_query($mysqlConnection, 'SET FOREIGN_KEY_CHECKS = 1');
            mysqli_close($mysqlConnection);

            $newConfValues = [
                'dolibarr_main_db_type' => 'mysqli',
                'dolibarr_main_db_host' => $destHost,
                'dolibarr_main_db_port' => $destPort,
                'dolibarr_main_db_name' => $destDatabase,
                'dolibarr_main_db_user' => $destUser,
                'dolibarr_main_db_pass' => $destPassword,
            ];
            $backupFilePath = DatabaseSwitchLib::patchDolibarrConf($confFilePath, $newConfValues, $backupDirectory);
            $logLines[] = "conf.php mis à jour → backup : {$backupFilePath}";

            $cleanupConnection = @mysqli_connect($destHost, $mysqlAdminUser, $mysqlAdminPassword, '', (int)$destPort);
            if ($cleanupConnection) {
                mysqli_query($cleanupConnection, "DROP DATABASE IF EXISTS `{$oldDbName}`");
                $logLines[] = "Ancienne base '{$oldDbName}' supprimée";
                mysqli_close($cleanupConnection);
            }

            @unlink($stagingNameFile);
            respond(true, 'Swap terminé — Dolibarr pointe maintenant sur MySQL/MariaDB',
                ['log' => implode("\n", $logLines), 'conf_bak' => $backupFilePath]);
        }
    }

    case 'step_done':

    {
        $freshConf = DatabaseSwitchLib::readDolibarrConf($confFilePath);
        $dbType    = $freshConf['dolibarr_main_db_type'] ?? 'inconnu';

        $logLines = [
            "Type dans conf.php : {$dbType}",
            "Base : ".($freshConf['dolibarr_main_db_name'] ?? '?'),
            "Hôte : ".($freshConf['dolibarr_main_db_host'] ?? '?'),
        ];

        if ($dbType === 'pgsql') {
            $pgConnection = DatabaseSwitchLib::pgConnect([
                'host' => $freshConf['dolibarr_main_db_host'] ?? $destHost,
                'port' => $freshConf['dolibarr_main_db_port'] ?? $destPort,
                'db'   => $freshConf['dolibarr_main_db_name'] ?? $destDatabase,
                'user' => $freshConf['dolibarr_main_db_user'] ?? $destUser,
                'pass' => $freshConf['dolibarr_main_db_pass'] ?? $destPassword,
            ]);
            if ($pgConnection) {
                $userCount = DatabaseSwitchLib::pgFetchScalar($pgConnection, "SELECT COUNT(*) FROM {$destPrefix}user");
                $logLines[] = "✅ PostgreSQL opérationnel — {$userCount} utilisateurs";

                $biViewsFile = DOL_DOCUMENT_ROOT.'/custom/databaseswitch/sql/views_bi_pgsql.sql';
                if (file_exists($biViewsFile)) {
                    $biSql = file_get_contents($biViewsFile);

                    $biSql = str_replace('llx_', $destPrefix, $biSql);

                    $biStatements = preg_split('/;\s*\n/', $biSql);
                    $biViewCount = 0;
                    foreach ($biStatements as $stmt) {
                        $stmt = trim($stmt);
                        if (stripos($stmt, 'CREATE') === false) continue;
                        try { $pgConnection->exec($stmt); $biViewCount++; } catch (\Exception $e) {
                            $logLines[] = "⚠️ Vue BI : ".mb_substr($e->getMessage(), 0, 100);
                        }
                    }
                    if ($biViewCount > 0) $logLines[] = "📊 {$biViewCount} vues BI recréées (PostgreSQL)";
                }

                respond(true, "🎉 Migration terminée ! Dolibarr tourne sur {$dbType}", ['log' => implode("\n", $logLines)]);
            }
            respond(false, 'Connexion PostgreSQL finale échouée — vérifiez manuellement conf.php', ['log' => implode("\n", $logLines)]);

        } else {
            $mysqlConnection = DatabaseSwitchLib::mysqlConnect([
                'host' => $freshConf['dolibarr_main_db_host'] ?? $destHost,
                'port' => $freshConf['dolibarr_main_db_port'] ?? $destPort,
                'db'   => $freshConf['dolibarr_main_db_name'] ?? $destDatabase,
                'user' => $freshConf['dolibarr_main_db_user'] ?? $destUser,
                'pass' => $freshConf['dolibarr_main_db_pass'] ?? $destPassword,
            ]);
            if ($mysqlConnection) {
                $userCount = DatabaseSwitchLib::mysqlFetchScalar($mysqlConnection, "SELECT COUNT(*) FROM {$destPrefix}user");
                $logLines[] = "✅ MySQL/MariaDB opérationnel — {$userCount} utilisateurs";

                $biViewsFile = DOL_DOCUMENT_ROOT.'/custom/databaseswitch/sql/views_bi_mysql.sql';
                if (file_exists($biViewsFile)) {
                    $biSql = file_get_contents($biViewsFile);
                    $biSql = str_replace('llx_', $destPrefix, $biSql);
                    $biStatements = preg_split('/;\s*\n/', $biSql);
                    $biViewCount = 0;
                    foreach ($biStatements as $stmt) {
                        $stmt = trim($stmt);
                        if (stripos($stmt, 'CREATE') === false) continue;
                        if (mysqli_query($mysqlConnection, $stmt)) { $biViewCount++; } else {
                            $logLines[] = "⚠️ Vue BI : ".mb_substr(mysqli_error($mysqlConnection), 0, 100);
                        }
                    }
                    if ($biViewCount > 0) $logLines[] = "📊 {$biViewCount} vues BI recréées (MySQL)";
                }

                mysqli_close($mysqlConnection);
                respond(true, "🎉 Migration terminée ! Dolibarr tourne sur {$dbType}", ['log' => implode("\n", $logLines)]);
            }
            respond(false, 'Connexion MySQL finale échouée — vérifiez manuellement conf.php', ['log' => implode("\n", $logLines)]);
        }
    }

    case 'rollback':

    {
        if (!$confBackupFile || !file_exists($confBackupFile)) {
            respond(false, 'Fichier backup introuvable');
        }

        $realBackupPath = realpath($confBackupFile);
        $allowedDirs = [
            realpath($backupDirectory),
            realpath(dirname($confFilePath)),
        ];
        $isAllowed = false;
        foreach ($allowedDirs as $dir) {
            if ($dir && strpos($realBackupPath, $dir) === 0) {
                $isAllowed = true;
                break;
            }
        }
        if (!$isAllowed) {
            respond(false, 'Chemin de backup non autorisé — le fichier doit être dans le répertoire de sauvegarde');
        }

        if (strpos(basename($confBackupFile), 'conf.php.bak') !== 0) {
            respond(false, 'Le fichier ne semble pas être un backup conf.php valide');
        }
        DatabaseSwitchLib::restoreDolibarrConf($confBackupFile, $confFilePath);
        $restoredConf = DatabaseSwitchLib::readDolibarrConf($confFilePath);
        respond(true,
            "conf.php restauré — type : ".($restoredConf['dolibarr_main_db_type'] ?? '?').
            " | base : ".($restoredConf['dolibarr_main_db_name'] ?? '?')
        );
    }

    default:
        respond(false, 'Action inconnue : '.htmlspecialchars($action, ENT_QUOTES));

    }
} catch (Throwable $exception) {

    $safeMessage = $exception->getMessage();

    $safeMessage = preg_replace('#/[a-zA-Z0-9_/.\-]+\.php#', '[fichier interne]', $safeMessage);
    respond(false, $safeMessage);
}

function pgTypeToMysqlType(string $pgType, int $maxLength = 255): string
{
    $typeMapping = [
        'integer'                    => 'INT',
        'bigint'                     => 'BIGINT',
        'smallint'                   => 'SMALLINT',
        'boolean'                    => 'TINYINT(1)',
        'text'                       => 'LONGTEXT',
        'character varying'          => 'VARCHAR('.min($maxLength, 16383).')',
        'character'                  => 'CHAR('.min($maxLength, 255).')',
        'timestamp without time zone'=> 'DATETIME',
        'timestamp with time zone'   => 'DATETIME',
        'date'                       => 'DATE',
        'time without time zone'     => 'TIME',
        'double precision'           => 'DOUBLE',
        'real'                       => 'FLOAT',
        'numeric'                    => 'DECIMAL(20,8)',
        'bytea'                      => 'LONGBLOB',
        'json'                       => 'LONGTEXT',
        'jsonb'                      => 'LONGTEXT',
        'uuid'                       => 'VARCHAR(36)',
        'inet'                       => 'VARCHAR(45)',
        'interval'                   => 'VARCHAR(64)',
    ];

    return $typeMapping[strtolower($pgType)] ?? 'LONGTEXT';
}
