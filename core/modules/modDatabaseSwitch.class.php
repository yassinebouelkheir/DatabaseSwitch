<?php
/**
 * DatabaseSwitch — Descripteur de module Dolibarr
 * Auteur : BOUELKHEIR Yassine
 */
if (!defined('DOL_VERSION')) die('Restricted access');

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modDatabaseSwitch extends DolibarrModules
{
    public function __construct($db)
    {
        parent::__construct($db);

        $this->numero       = 500010;
        $this->rights_class = 'databaseswitch';
        $this->family       = 'devtools';
        $this->familyinfo   = ['devtools' => ['position' => '090', 'label' => 'Outils de Développement']];
        $this->name         = 'DatabaseSwitch';
        $this->description  = 'Migration complète et sécurisée de votre base de données Dolibarr entre MariaDB et PostgreSQL dans les deux sens. Sauvegarde automatique, vérification ligne par ligne, bascule atomique sans interruption de service.';
        $this->version      = '1.1.1';
        $this->const_name   = 'MAIN_MODULE_DATABASESWITCH';
        $this->editor_name  = 'BOUELKHEIR Yassine';
        $this->editor_url   = '';

        $this->phpmin           = [7, 4];
        $this->need_dolibarr_version = [14, 0];

        $this->picto = 'fa-database';

        $this->module_parts = [
            'css' => ['/custom/databaseswitch/css/databaseswitch.css'],
        ];

        $this->config_page_url = [];

        $this->menu[0] = [
            'fk_menu'  => '',
            'type'     => 'top',
            'titre'    => 'DatabaseSwitch',
            'mainmenu' => 'databaseswitch',
            'url'      => '/databaseswitch/admin/index.php',
            'langs'    => 'databaseswitch@databaseswitch',
            'position' => 101,
            'enabled'  => 'isModEnabled("databaseswitch")',
            'perms'    => '$user->admin',
            'target'   => '',
            'user'     => 2,
            'picto'    => 'fa-database',
        ];
    }

    public function init($options = '')
    {
        $result = parent::init($options);

        $confPath = DOL_DOCUMENT_ROOT.'/conf/conf.php';
        if (file_exists($confPath) && !is_writable($confPath)) {

            @chmod($confPath, 0640);
            clearstatcache(true, $confPath);

            if (!is_writable($confPath) && function_exists('exec')) {
                $chownBin = file_exists('/usr/bin/chown') ? '/usr/bin/chown' : '/bin/chown';
                @exec('sudo '.$chownBin.' www-data:www-data '.escapeshellarg($confPath).' 2>&1');
                @exec('sudo chmod 640 '.escapeshellarg($confPath).' 2>&1');
            }
        }

        $backupDir = '/var/backups/dolibarr_migration';
        if (!is_dir($backupDir)) {

            @mkdir($backupDir, 0750, true);
        }
        if (!is_dir($backupDir) || !is_writable($backupDir)) {

            if (function_exists('exec')) {
                $mkdirBin = file_exists('/usr/bin/mkdir') ? '/usr/bin/mkdir' : '/bin/mkdir';
                $chownBin = file_exists('/usr/bin/chown') ? '/usr/bin/chown' : '/bin/chown';
                @exec('sudo '.$mkdirBin.' -p '.escapeshellarg($backupDir).' 2>&1');
                @exec('sudo '.$chownBin.' www-data:www-data '.escapeshellarg($backupDir).' 2>&1');
                @exec('sudo chmod 750 '.escapeshellarg($backupDir).' 2>&1');
            }
        }

        return $result;
    }
}
