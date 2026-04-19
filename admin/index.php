<?php
/**
 * DatabaseSwitch — Interface d'administration
 * Auteur : BOUELKHEIR Yassine
 *
 * Détecte automatiquement le moteur de base de données actuel depuis conf.php
 * et propose la migration dans la bonne direction (MariaDB→PG ou PG→MariaDB).
 */
$res = 0;
foreach (['../../main.inc.php', '../../../main.inc.php', '../../../../main.inc.php'] as $path) {
    if (!$res && file_exists($path)) { $res = @include $path; break; }
}
if (!$res)         die('main.inc.php introuvable');
if (!$user->admin) accessforbidden();

$langs->load('databaseswitch@databaseswitch');
llxHeader('', 'DatabaseSwitch', '');

require_once DOL_DOCUMENT_ROOT.'/custom/databaseswitch/lib/databaseswitch.lib.php';

$confFilePath  = DOL_DOCUMENT_ROOT.'/conf/conf.php';
$currentConf   = DatabaseSwitchLib::readDolibarrConf($confFilePath);

$currentDbType = $currentConf['dolibarr_main_db_type']   ?? 'mysqli';
$currentHost   = $currentConf['dolibarr_main_db_host']   ?? 'localhost';
$currentPort   = $currentConf['dolibarr_main_db_port']   ?? ($currentDbType === 'pgsql' ? '5432' : '3306');
$currentDbName = $currentConf['dolibarr_main_db_name']   ?? 'dolibarr';
$currentUser   = $currentConf['dolibarr_main_db_user']   ?? '';
$currentPrefix = $currentConf['dolibarr_main_db_prefix'] ?? 'llx_';

$isCurrentlyPostgres = ($currentDbType === 'pgsql');

$migrationDirection = $isCurrentlyPostgres ? 'pg2mysql' : 'mysql2pg';
$directionLabel     = $isCurrentlyPostgres ? 'PostgreSQL → MariaDB' : 'MariaDB → PostgreSQL';
$directionIcon      = $isCurrentlyPostgres ? '🐘 → 🐬' : '🐬 → 🐘';
$destinationLabel   = $isCurrentlyPostgres ? 'MySQL/MariaDB' : 'PostgreSQL';
$destinationPort    = $isCurrentlyPostgres ? '3306' : '5432';
$destinationUser    = $isCurrentlyPostgres ? 'dolibarr' : 'dolibarr_pg';
?>

<style>
:root {
    --color-blue:   #1a73e8;
    --color-green:  #34a853;
    --color-red:    #ea4335;
    --color-yellow: #f9ab00;
    --color-gray:   #5f6368;
    --color-border: #e8eaed;
    --color-bg:     #f8f9fa;
}
* { box-sizing: border-box; }

.dbm-wrap {
    max-width: 960px;
    margin: 24px auto;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 14px;
    color: #202124;
}

/* ── Header ── */
.dbm-header {
    background: linear-gradient(135deg, #0d47a1, #1a73e8);
    color: #fff;
    border-radius: 12px;
    padding: 24px 28px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.dbm-header h1   { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
.dbm-header p    { margin: 4px 0 0; opacity: .85; font-size: 13px; }
.dbm-dir-badge   { background: rgba(255,255,255,.2); border-radius: 8px; padding: 8px 16px; font-size: 20px; font-weight: 700; }

/* ── Cards ── */
.dbm-card { background: #fff; border: 1px solid var(--color-border); border-radius: 10px; padding: 22px; margin-bottom: 18px; }
.dbm-card h2 { margin: 0 0 14px; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; }

/* ── Badges ── */
.dbm-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.dbm-badge-blue   { background: #e8f0fe; color: var(--color-blue); }
.dbm-badge-green  { background: #e6f4ea; color: var(--color-green); }
.dbm-badge-orange { background: #fef3e2; color: #e37400; }

/* ── Alertes ── */
.dbm-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; line-height: 1.6; }
.dbm-alert-info { background: #e8f0fe; border: 1px solid #aecbfa; color: #1a3c6d; }
.dbm-alert-warn { background: #fef3e2; border: 1px solid #fbbc04; color: #7c5c00; }

/* ── Grilles de champs ── */
.dbm-grid-2 { display: grid; grid-template-columns: 1fr 1fr;        gap: 12px; }
.dbm-grid-3 { display: grid; grid-template-columns: 2fr 1fr 1fr;    gap: 12px; }
.dbm-field  { display: flex; flex-direction: column; gap: 4px; }
.dbm-field label { font-size: 11px; font-weight: 600; color: var(--color-gray); text-transform: uppercase; letter-spacing: .4px; }
.dbm-field input { padding: 9px 12px; border: 1.5px solid var(--color-border); border-radius: 6px; font-size: 13px; color: #202124; background: #fafafa; transition: border .15s; }
.dbm-field input:focus { outline: none; border-color: var(--color-blue); background: #fff; }

/* ── Boutons ── */
.dbm-btn { display: inline-flex; align-items: center; gap: 7px; padding: 10px 22px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .15s; white-space: nowrap; }
.dbm-btn-primary { background: var(--color-blue); color: #fff; }
.dbm-btn-primary:hover { background: #1557b0; }
.dbm-btn-primary:disabled { background: #9aa0a6; cursor: not-allowed; }
.dbm-btn-ghost  { background: #e8f0fe; color: var(--color-blue); border: 1.5px solid #aecbfa; }
.dbm-btn-ghost:hover { background: #d2e3fc; }
.dbm-btn-danger { background: #fce8e6; color: var(--color-red); border: 1.5px solid #f5c6c2; }
.dbm-btn-danger:hover { background: #f5c6c2; }

/* ── Info-boxes (résumé conf) ── */
.dbm-info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
.dbm-info-box  { background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 8px; padding: 12px 16px; text-align: center; }
.dbm-info-box .value { font-size: 18px; font-weight: 700; color: var(--color-blue); word-break: break-all; }
.dbm-info-box .label { font-size: 11px; color: var(--color-gray); margin-top: 2px; }

/* ── Barre de progression ── */
.dbm-progress { height: 6px; background: var(--color-border); border-radius: 3px; margin-bottom: 18px; overflow: hidden; }
.dbm-progress-fill { height: 100%; background: linear-gradient(90deg, var(--color-blue), var(--color-green)); border-radius: 3px; transition: width .6s ease; width: 0; }

/* ── Liste des étapes ── */
.dbm-steps { display: flex; flex-direction: column; }
.dbm-step  { display: flex; align-items: flex-start; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--color-border); }
.dbm-step:last-child { border-bottom: none; }

.dbm-step-num { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; margin-top: 2px; transition: all .3s; }
.dbm-step-num.waiting { background: #f1f3f4; color: var(--color-gray); }
.dbm-step-num.running { background: #e8f0fe; color: var(--color-blue); animation: dbm-pulse 1s infinite; }
.dbm-step-num.success { background: #e6f4ea; color: var(--color-green); }
.dbm-step-num.failed  { background: #fce8e6; color: var(--color-red); }

@keyframes dbm-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }

.dbm-step-body   { flex: 1; min-width: 0; }
.dbm-step-title  { font-weight: 600; font-size: 13px; }
.dbm-step-desc   { color: var(--color-gray); font-size: 12px; margin-top: 2px; }

/* ── Log terminal ── */
.dbm-log {
    font-family: 'Fira Code', 'Consolas', monospace;
    font-size: 11px;
    background: #1e1e2e;
    color: #cdd6f4;
    border-radius: 6px;
    padding: 10px 12px;
    margin-top: 8px;
    max-height: 400px;
    overflow-y: auto;
    display: none;
    white-space: pre-wrap;
    word-break: break-all;
    line-height: 1.5;
}
.dbm-log.visible { display: block; }
/* Log migrate plus grand — peut contenir beaucoup de DEBUG */
#steplog-migrate { max-height: 600px; }

</style>

<div class="dbm-wrap">
<form onsubmit="return false" autocomplete="off">

    <!-- ── Header ── -->
    <div class="dbm-header">
        <div>
            <h1>⚡ DatabaseSwitch</h1>
            <p>Direction détectée automatiquement depuis <code>conf.php</code> — migration bidirectionnelle sans perte de données</p>
            <p style="margin-top:6px;opacity:.7;font-size:12px">par <strong>BOUELKHEIR Yassine</strong> · v1.1.0</p>
        </div>
        <div class="dbm-dir-badge"><?= $directionIcon ?></div>
    </div>

    <!-- ── Setup / Prérequis (auto-vérification + auto-correction) ── -->
    <div class="dbm-card" id="setup-card">
        <h2>🛠️ Vérification du système
            <span class="dbm-badge dbm-badge-orange" id="setup-badge">Vérification...</span>
        </h2>

        <div id="setup-checks" style="display:flex;flex-direction:column;gap:5px">
            <div style="text-align:center;padding:20px;color:var(--color-gray)">
                <span style="display:inline-block;width:14px;height:14px;border:2px solid #9aa0a6;border-top-color:#1a73e8;border-radius:50%;animation:dbm-pulse .8s linear infinite"></span>
                Vérification et correction automatique en cours…
            </div>
        </div>

        <!-- Commandes SSH (affichées UNIQUEMENT si des choses nécessitent root) -->
        <div id="panel-ssh" style="display:none;margin-top:16px;padding:16px;background:linear-gradient(135deg,#1e1e2e,#2d2d3f);border-radius:8px">
            <div style="font-weight:600;color:#cdd6f4;margin-bottom:6px;font-size:14px">💻 Commandes à exécuter en SSH <span style="opacity:.6;font-size:11px">(une seule fois)</span></div>
            <div style="font-size:11px;color:#9399b2;margin-bottom:10px">
                Copiez-collez ce bloc dans votre terminal serveur, puis rechargez cette page.
            </div>
            <pre id="ssh-commands" style="background:#11111b;color:#a6e3a1;padding:14px;border-radius:6px;font-size:12px;line-height:1.7;overflow-x:auto;white-space:pre-wrap;user-select:all;margin:0;border:1px solid #313244"></pre>
            <button class="dbm-btn" style="margin-top:10px;background:#313244;color:#cdd6f4;font-size:12px"
                    onclick="navigator.clipboard.writeText(document.getElementById('ssh-commands').textContent).then(()=>this.textContent='✅ Copié !')">
                📋 Copier les commandes
            </button>
        </div>
    </div>

    <!-- ── Base de données actuelle ── -->
    <div class="dbm-card">
        <h2>📍 Base de données actuelle
            <span class="dbm-badge <?= $isCurrentlyPostgres ? 'dbm-badge-blue' : 'dbm-badge-green' ?>">
                <?= $isCurrentlyPostgres ? 'PostgreSQL' : 'MariaDB/MySQL' ?>
            </span>
        </h2>
        <div class="dbm-info-grid">
            <div class="dbm-info-box"><div class="value"><?= htmlspecialchars($currentDbType) ?></div><div class="label">Type</div></div>
            <div class="dbm-info-box"><div class="value"><?= htmlspecialchars($currentHost) ?></div><div class="label">Hôte</div></div>
            <div class="dbm-info-box"><div class="value"><?= htmlspecialchars($currentDbName) ?></div><div class="label">Base</div></div>
            <div class="dbm-info-box"><div class="value"><?= htmlspecialchars($currentPrefix) ?></div><div class="label">Préfixe</div></div>
        </div>
        <div class="dbm-alert dbm-alert-info">
            🔄 Migration proposée : <strong><?= $directionLabel ?></strong>
            — la base source ne sera <strong>jamais modifiée</strong>.
            conf.php sera mis à jour uniquement après vérification que 100% des lignes correspondent.
        </div>
    </div>

    <!-- ── Paramètres source (pré-remplis depuis conf.php) ── -->
    <div class="dbm-card">
        <h2>🗄️ Base source <span class="dbm-badge dbm-badge-blue">Pré-remplie depuis conf.php</span></h2>
        <div class="dbm-grid-3">
            <div class="dbm-field"><label>Hôte</label><input type="text" id="src-host" value="<?= htmlspecialchars($currentHost) ?>"></div>
            <div class="dbm-field"><label>Port</label><input type="text" id="src-port" value="<?= htmlspecialchars($currentPort) ?>"></div>
            <div class="dbm-field"><label>Préfixe</label><input type="text" id="src-prefix" value="<?= htmlspecialchars($currentPrefix) ?>"></div>
        </div>
        <div class="dbm-grid-2" style="margin-top:12px">
            <div class="dbm-field"><label>Base de données</label><input type="text" id="src-db" value="<?= htmlspecialchars($currentDbName) ?>"></div>
            <div class="dbm-field"><label>Utilisateur</label><input type="text" id="src-user" value="<?= htmlspecialchars($currentUser) ?>"></div>
        </div>
        <div class="dbm-grid-2" style="margin-top:12px">
            <div class="dbm-field">
                <label>Mot de passe</label>
                <input type="password" id="src-pass" placeholder="Mot de passe source (non stocké dans conf.php)">
            </div>
            <div style="display:flex;align-items:flex-end;gap:10px">
                <button class="dbm-btn dbm-btn-ghost" onclick="testConnection('src')">🔌 Tester</button>
                <span id="src-test-result" style="font-size:12px"></span>
            </div>
        </div>
    </div>

    <!-- ── Paramètres destination ── -->
    <div class="dbm-card">
        <h2>🎯 Base destination — <?= htmlspecialchars($destinationLabel) ?></h2>
        <div class="dbm-alert dbm-alert-warn">
            ⚙️ Une base <strong>staging temporaire</strong> sera créée d'abord
            (ex: <code><?= htmlspecialchars($currentDbName) ?>_mig_YYYYMMDD_HHMMSS</code>).
            conf.php ne sera modifié qu'après vérification complète.
        </div>
        <div class="dbm-grid-3">
            <div class="dbm-field"><label>Hôte</label><input type="text" id="dst-host" value="localhost"></div>
            <div class="dbm-field"><label>Port</label><input type="text" id="dst-port" value="<?= $destinationPort ?>"></div>
            <div class="dbm-field"><label>Préfixe (même que source)</label><input type="text" id="dst-prefix" value="<?= htmlspecialchars($currentPrefix) ?>"></div>
        </div>
        <div class="dbm-grid-2" style="margin-top:12px">
            <div class="dbm-field">
                <label>Nom de la base destination</label>
                <input type="text" id="dst-db" value="<?= htmlspecialchars($currentDbName) ?>">
            </div>
            <div class="dbm-field">
                <label>Utilisateur <?= $isCurrentlyPostgres ? 'MySQL' : 'PostgreSQL' ?></label>
                <input type="text" id="dst-user" value="<?= $destinationUser ?>">
            </div>
        </div>
        <div class="dbm-grid-2" style="margin-top:12px">
            <div class="dbm-field">
                <label>Mot de passe</label>
                <input type="password" id="dst-pass" placeholder="Mot de passe destination">
            </div>
            <div style="display:flex;align-items:flex-end;gap:10px">
                <button class="dbm-btn dbm-btn-ghost" onclick="testConnection('dst')">🔌 Tester</button>
                <span id="dst-test-result" style="font-size:12px"></span>
            </div>
        </div>
        <div class="dbm-field" style="margin-top:12px">
            <label>Répertoire des sauvegardes</label>
            <input type="text" id="backup-dir" value="/var/backups/dolibarr_migration">
        </div>

        <?php if ($migrationDirection === 'pg2mysql'): ?>
        <div class="dbm-field" style="margin-top:16px; padding:12px; background:#fff8e1; border-left:4px solid #f59e0b; border-radius:4px">
            <div style="font-weight:bold; color:#92400e; margin-bottom:8px">⚠ Utilisateur admin MySQL requis</div>
            <div style="font-size:12px; color:#78350f; margin-bottom:10px">
                Les opérations CREATE/RENAME/DROP DATABASE nécessitent un compte avec les droits correspondants (ex: <code>root</code>).
                L'utilisateur destination (<code>dolibarruser</code>) n'a généralement que SELECT.
            </div>
            <div style="display:flex; gap:12px">
                <div style="flex:1">
                    <label style="font-size:12px">Utilisateur admin MySQL</label>
                    <input type="text" id="mysql-admin-user" placeholder="root" style="width:100%">
                </div>
                <div style="flex:1">
                    <label style="font-size:12px">Mot de passe admin MySQL</label>
                    <input type="password" id="mysql-admin-pass" placeholder="Mot de passe root" style="width:100%">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($migrationDirection === 'mysql2pg'): ?>
        <div class="dbm-field" style="margin-top:16px; padding:12px; background:#eff6ff; border-left:4px solid #3b82f6; border-radius:4px">
            <div style="font-weight:bold; color:#1e40af; margin-bottom:8px">⚠ Utilisateur superuser PostgreSQL requis</div>
            <div style="font-size:12px; color:#1e3a8a; margin-bottom:10px">
                Les opérations CREATE/RENAME/DROP DATABASE nécessitent un superuser PostgreSQL (ex: <code>postgres</code>).
                L'utilisateur destination peut ne pas avoir ces droits.
            </div>
            <div style="display:flex; gap:12px">
                <div style="flex:1">
                    <label style="font-size:12px">Superuser PostgreSQL</label>
                    <input type="text" id="pg-admin-user" placeholder="postgres" style="width:100%">
                </div>
                <div style="flex:1">
                    <label style="font-size:12px">Mot de passe superuser</label>
                    <input type="password" id="pg-admin-pass" placeholder="Mot de passe postgres" style="width:100%">
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Panel de migration ── -->
    <div class="dbm-card">
        <h2>🚀 Migration — <?= $directionLabel ?></h2>

        <div class="dbm-progress">
            <div class="dbm-progress-fill" id="migration-progress"></div>
        </div>

        <div class="dbm-steps" id="steps-container">
        <?php
        $migrationSteps = $isCurrentlyPostgres ? [
            ['id' => 'check',    'num' => '1', 'title' => 'Vérification des prérequis',        'desc' => 'pg_dump, mysql client, connexions'],
            ['id' => 'backup',   'num' => '2', 'title' => 'Sauvegarde PostgreSQL',              'desc' => 'pg_dump complet en .sql.gz — source intacte'],
            ['id' => 'staging',  'num' => '3', 'title' => 'Création base staging MySQL',        'desc' => 'Base temporaire horodatée — anciennes stagings nettoyées'],
            ['id' => 'migrate',  'num' => '4', 'title' => 'Copie des données PG → MySQL',      'desc' => 'Transfer par lots via PDO — types et DEFAULT convertis automatiquement'],
            ['id' => 'defaults', 'num' => '5', 'title' => 'Vérification des valeurs DEFAULT',  'desc' => 'Confirmation que les DEFAULT PG ont bien été transcrits dans MySQL'],
            ['id' => 'seqfix',   'num' => '6', 'title' => 'Reset AUTO_INCREMENT MySQL',         'desc' => 'Tous les compteurs remis à MAX(id)+1'],
            ['id' => 'verify',   'num' => '7', 'title' => 'Vérification 100% des lignes',      'desc' => 'Toutes les tables comparées avant de toucher conf.php'],
            ['id' => 'swap',     'num' => '8', 'title' => 'Swap staging → production',          'desc' => 'Renomme la staging en nom final, patche conf.php'],
            ['id' => 'done',     'num' => '9', 'title' => 'Vérification finale',                'desc' => 'Test de connexion Dolibarr sur la nouvelle base'],
        ] : [
            ['id' => 'check',    'num' => '1', 'title' => 'Vérification des prérequis',        'desc' => 'pgloader, psql, connexions MariaDB et PostgreSQL'],
            ['id' => 'backup',   'num' => '2', 'title' => 'Sauvegarde MariaDB',                 'desc' => 'mysqldump complet en .sql.gz — source intacte'],
            ['id' => 'staging',  'num' => '3', 'title' => 'Création base staging PostgreSQL',   'desc' => 'Base temporaire horodatée — anciennes stagings nettoyées'],
            ['id' => 'migrate',  'num' => '4', 'title' => 'Migration MySQL → PostgreSQL',       'desc' => 'pgloader : tables, index, FK, conversion de types automatique'],
            ['id' => 'defaults', 'num' => '5', 'title' => 'Restauration des valeurs DEFAULT',  'desc' => 'pgloader supprime les DEFAULT MySQL — cette étape les restaure dans PG'],
            ['id' => 'seqfix',   'num' => '6', 'title' => 'Reset séquences PostgreSQL',         'desc' => 'Toutes les séquences SERIAL remises à MAX(id)+1'],
            ['id' => 'verify',   'num' => '7', 'title' => 'Vérification 100% des lignes',      'desc' => 'Toutes les tables comparées avant de toucher conf.php'],
            ['id' => 'swap',     'num' => '8', 'title' => 'Swap staging → production',          'desc' => 'Renomme la staging en nom final, patche conf.php'],
            ['id' => 'done',     'num' => '9', 'title' => 'Vérification finale',                'desc' => 'Test de connexion Dolibarr sur la nouvelle base'],
        ];
        foreach ($migrationSteps as $step): ?>
        <div class="dbm-step">
            <div class="dbm-step-num waiting" id="stepnum-<?= $step['id'] ?>"><?= $step['num'] ?></div>
            <div class="dbm-step-body">
                <div class="dbm-step-title"><?= $step['title'] ?></div>
                <div class="dbm-step-desc" id="stepdesc-<?= $step['id'] ?>"><?= $step['desc'] ?></div>
                <div class="dbm-log" id="steplog-<?= $step['id'] ?>"></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <div style="display:flex;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap">
            <button class="dbm-btn dbm-btn-primary" id="btn-start-migration" onclick="startMigration()">
                ⚡ Lancer <?= $directionLabel ?>
            </button>

            <span id="global-status" style="font-size:13px;flex:1"></span>
        </div>

    </div>

</form>
</div>

<script>
const AJAX_URL       = '<?= dol_buildpath('/databaseswitch/ajax/migrate.php', 1) ?>';
const MIGRATION_DIR  = '<?= $migrationDirection ?>';
let confBackupFile   = null;

function getFieldValue(fieldId) {
    const el = document.getElementById(fieldId);
    return el ? el.value.trim() : '';
}

const DEFAULT_SRC_PORT = MIGRATION_DIR === 'pg2mysql' ? '5432' : '3306';
const DEFAULT_DST_PORT = MIGRATION_DIR === 'mysql2pg' ? '5432' : '3306';

function buildRequestPayload(extraFields = {}) {
    return {
        direction:  MIGRATION_DIR,
        src_host:   getFieldValue('src-host')   || 'localhost',
        src_port:   getFieldValue('src-port')   || DEFAULT_SRC_PORT,
        src_db:     getFieldValue('src-db')     || 'dolibarr',
        src_user:   getFieldValue('src-user'),
        src_pass:   getFieldValue('src-pass'),
        src_prefix: getFieldValue('src-prefix') || 'llx_',
        dst_host:   getFieldValue('dst-host')   || 'localhost',
        dst_port:   getFieldValue('dst-port')   || DEFAULT_DST_PORT,
        dst_db:     getFieldValue('dst-db')     || 'dolibarr',
        dst_user:   getFieldValue('dst-user'),
        dst_pass:   getFieldValue('dst-pass'),
        dst_prefix: getFieldValue('dst-prefix') || 'llx_',
        backup_dir:        getFieldValue('backup-dir') || '/var/backups/dolibarr_migration',
        mysql_admin_user:  getFieldValue('mysql-admin-user') || '',
        mysql_admin_pass:  getFieldValue('mysql-admin-pass') || '',
        pg_admin_user:     getFieldValue('pg-admin-user') || '',
        pg_admin_pass:     getFieldValue('pg-admin-pass') || '',
        ...extraFields,
    };
}

function setStepStatus(stepId, status) {
    const numEl = document.getElementById('stepnum-' + stepId);
    if (!numEl) return;
    numEl.className = 'dbm-step-num ' + status;
    if (status === 'success') numEl.textContent = '✓';
    if (status === 'failed')  numEl.textContent = '✗';
}

function setStepDescription(stepId, message) {
    const el = document.getElementById('stepdesc-' + stepId);
    if (el) el.textContent = message;
}

function appendStepLog(stepId, logText) {
    const logEl = document.getElementById('steplog-' + stepId);
    if (!logEl) return;
    logEl.classList.add('visible');
    logEl.textContent += logText + '\n';
    logEl.scrollTop = logEl.scrollHeight;
}

function setMigrationProgress(percent) {
    const el = document.getElementById('migration-progress');
    if (el) el.style.width = percent + '%';
}

async function postJson(payload) {
    const response = await fetch(AJAX_URL, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
    });
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        return { success: false, error: 'Réponse non-JSON : ' + text.substring(0, 300) };
    }
}

async function testConnection(side) {
    const resultEl = document.getElementById(side + '-test-result');
    resultEl.textContent = '…';

    const payload  = buildRequestPayload({ action: 'test_conn', side });
    const response = await postJson(payload);

    resultEl.textContent = response.success ? '✅ ' + response.message : '❌ ' + response.error;
    resultEl.style.color = response.success ? '#34a853' : '#ea4335';
}

async function runMigrationStep(actionName, progressStart, progressEnd) {
    const stepId = actionName.replace('step_', '');
    setStepStatus(stepId, 'running');

    const payload  = buildRequestPayload({ action: actionName, conf_bak: confBackupFile });
    const response = await postJson(payload);

    setMigrationProgress(progressEnd);
    if (response.log)     appendStepLog(stepId, response.log);
    if (response.message) setStepDescription(stepId, response.message);

    if (response.success) {
        setStepStatus(stepId, 'success');

        if (response.conf_bak) {
            confBackupFile = response.conf_bak;
        }
        return response;
    } else {
        setStepStatus(stepId, 'failed');
        throw new Error(response.error || 'Erreur inconnue à l\'étape ' + stepId);
    }
}

async function startMigration() {
    const startButton  = document.getElementById('btn-start-migration');
    const statusEl     = document.getElementById('global-status');

    if (!startButton || !statusEl) {
        console.error('DatabaseSwitch: bouton ou status introuvable');
        return;
    }

    startButton.disabled = true;
    statusEl.textContent = '⏳ Initialisation…';
    confBackupFile       = null;
    var _rp = document.getElementById('rollback-panel'); if (_rp) _rp.style.display = 'none';

    try {

        const stepIds = MIGRATION_DIR === 'mysql2pg'
            ? ['check','backup','staging','migrate','defaults','seqfix','verify','swap','done']
            : ['check','backup','staging','migrate','defaults','seqfix','verify','swap','done'];
        stepIds.forEach(stepId => {
            setStepStatus(stepId, 'waiting');
            const logEl = document.getElementById('steplog-' + stepId);
            if (logEl) { logEl.classList.remove('visible'); logEl.textContent = ''; }
        });

        setMigrationProgress(0);

        const steps = MIGRATION_DIR === 'mysql2pg' ? [
            { action: 'step_check',    progressStart:  0, progressEnd:  10 },
            { action: 'step_backup',   progressStart: 10, progressEnd:  22 },
            { action: 'step_staging',  progressStart: 22, progressEnd:  32 },
            { action: 'step_migrate',  progressStart: 32, progressEnd:  55 },
            { action: 'step_defaults', progressStart: 55, progressEnd:  65 },
            { action: 'step_seqfix',   progressStart: 65, progressEnd:  75 },
            { action: 'step_verify',   progressStart: 75, progressEnd:  87 },
            { action: 'step_swap',     progressStart: 87, progressEnd:  95 },
            { action: 'step_done',     progressStart: 95, progressEnd: 100 },
        ] : [
            { action: 'step_check',    progressStart:  0, progressEnd:  10 },
            { action: 'step_backup',   progressStart: 10, progressEnd:  22 },
            { action: 'step_staging',  progressStart: 22, progressEnd:  32 },
            { action: 'step_migrate',  progressStart: 32, progressEnd:  55 },
            { action: 'step_defaults', progressStart: 55, progressEnd:  65 },
            { action: 'step_seqfix',   progressStart: 65, progressEnd:  75 },
            { action: 'step_verify',   progressStart: 75, progressEnd:  87 },
            { action: 'step_swap',     progressStart: 87, progressEnd:  95 },
            { action: 'step_done',     progressStart: 95, progressEnd: 100 },
        ];

        for (const step of steps) {
            statusEl.textContent = '⏳ ' + step.action.replace('step_', '') + '…';
            await runMigrationStep(step.action, step.progressStart, step.progressEnd);
        }
        statusEl.innerHTML = '<span style="color:#34a853;font-weight:700">✅ Migration terminée !</span> '
            + '<button class="dbm-btn dbm-btn-primary" style="margin-left:12px" onclick="location.reload()">🔄 Actualiser la page</button>';
    } catch (error) {
        console.error('DatabaseSwitch error:', error);
        statusEl.innerHTML = '<span style="color:#ea4335;font-weight:700">❌ ' + (error.message || error) + '</span>';
        startButton.disabled = false;
    }
}

function renderSetupResults(items, sshCommands) {
    const container = document.getElementById('setup-checks');
    const badge     = document.getElementById('setup-badge');

    let html = '';
    let allOk = true;

    items.forEach(item => {
        if (!item.ok) allOk = false;
        const bg   = item.ok ? '#f0fdf4' : (item.fixed ? '#fffbeb' : '#fef2f2');
        const icon = item.ok ? '✅' : (item.fixed ? '🔧' : '❌');
        const statusColor = item.ok ? '#15803d' : (item.fixed ? '#a16207' : '#dc2626');

        html += `<div style="display:flex;align-items:center;gap:10px;padding:8px 14px;background:${bg};border-radius:6px;font-size:13px">
            <span style="flex-shrink:0;font-size:15px">${icon}</span>
            <span style="flex:1">
                <strong>${item.label}</strong>
                <span style="color:${statusColor};margin-left:6px">${item.detail}</span>
            </span>
        </div>`;
    });

    container.innerHTML = html;

    if (allOk) {
        badge.textContent = '✅ Tout est OK';
        badge.className = 'dbm-badge dbm-badge-green';
    } else {
        const fails = items.filter(i => !i.ok && !i.fixed).length;
        const fixes = items.filter(i => i.fixed).length;
        let txt = '';
        if (fails > 0) txt += fails + ' action(s) SSH requise(s)';
        if (fixes > 0) txt += (txt ? ' · ' : '') + fixes + ' corrigé(s) auto';
        badge.textContent = txt;
        badge.className = 'dbm-badge dbm-badge-orange';
    }

    const sshPanel = document.getElementById('panel-ssh');
    if (sshCommands && sshCommands.length > 0) {
        document.getElementById('ssh-commands').textContent = sshCommands.join('\n');
        sshPanel.style.display = 'block';
    } else {
        sshPanel.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const payload = {
            action:     'setup_auto',
            direction:  MIGRATION_DIR,
            src_host:   getFieldValue('src-host')  || 'localhost',
            src_port:   getFieldValue('src-port')  || DEFAULT_SRC_PORT,
            src_db:     getFieldValue('src-db')     || 'dolibarr',
            src_user:   getFieldValue('src-user'),
            src_pass:   getFieldValue('src-pass'),
            dst_host:   getFieldValue('dst-host')  || 'localhost',
            dst_port:   getFieldValue('dst-port')  || DEFAULT_DST_PORT,
            dst_db:     getFieldValue('dst-db')     || 'dolibarr',
            dst_user:   getFieldValue('dst-user'),
            dst_pass:   getFieldValue('dst-pass'),
            backup_dir: getFieldValue('backup-dir') || '/var/backups/dolibarr_migration',
        };
        const resp = await postJson(payload);
        if (resp.items) {
            renderSetupResults(resp.items, resp.ssh_commands || []);
        } else {
            document.getElementById('setup-checks').innerHTML =
                '<div style="color:#dc2626;padding:10px">Erreur : ' + (resp.error || 'Réponse invalide') + '</div>';
        }
    } catch (e) {
        document.getElementById('setup-checks').innerHTML =
            '<div style="color:#dc2626;padding:10px">Erreur réseau : ' + e.message + '</div>';
    }
});

</script>

<?php llxFooter(); ?>
