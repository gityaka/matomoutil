<?php
  /**
   * MatomoUtil - bootstrap script for matomo.
   *
   * @link https://github.com/gityaka/matomoutil
   * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
   */

  use Piwik\Translation;
  use Piwik\DbHelper;
  use Piwik\Plugins\UsersManager;
  use Piwik\Access;
  use Piwik\Auth\Password;
  use Piwik\Db;
  use Piwik\SettingsServer;
  use Piwik\Updater;
  use Piwik\Plugin\Manager;
  use Piwik\Plugins\Installation\ServerFilesGenerator;
  use Piwik\Plugins\SitesManager\API as APISitesManager;

  /**
   * Class MatomoUtil
   * Used for setting up Matomo after the files are already in place. (ex. phingme deployMatomo)
   */
  class MatomoUtil
  {
    /**
     * @var array of CLI options
     */
    private $aOptions;

    /**
     * MatomoUtil constructor
     */
    public function __construct()
    {
      // get CLI arguments
      $this->aOptions = getopt("a:b:c:d:e:f:g:h:i:j:k:l:m:n:o:p:q:r:s:");

      // These constants are used inside of the Matomo/piwik library
      // as of version 4.5.0 and are required for correct installation
      if (!defined('PIWIK_DOCUMENT_ROOT'))
      {
        define('PIWIK_DOCUMENT_ROOT', $this->aOptions['a']);
      }

      if (file_exists(PIWIK_DOCUMENT_ROOT . '/bootstrap.php'))
      {
        require_once PIWIK_DOCUMENT_ROOT . '/bootstrap.php';
      }

      if (!defined('PIWIK_INCLUDE_PATH'))
      {
        define('PIWIK_INCLUDE_PATH', PIWIK_DOCUMENT_ROOT);
      }

      require_once PIWIK_DOCUMENT_ROOT . '/core/bootstrap.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Plugin/Controller.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Exception/NotYetInstalledException.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Plugin/ControllerAdmin.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Singleton.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Plugin/Manager.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Plugin.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Common.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Piwik.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/IP.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/UrlHelper.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Url.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/SettingsPiwik.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/SettingsServer.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Tracker.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Config.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Translation/Translator.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Tracker/Cache.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Tracker/Request.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/Cookie.php';
      require_once PIWIK_DOCUMENT_ROOT . '/core/API/CORSHandler.php';
    }

    /**
     * run installation routines
     */
    public function execute()
    {
      $this->initMatomo();
      $this->setupDb();
      $this->updateComponents();
    }

    /**
     * initialize matomo environment
     */
    public function initMatomo() : void
    {
      echo "Initializing environment...\n";

      //have to set this for proper initialization
      SettingsServer::setIsNotTrackerApiRequest();

      //initialize environment
      $environment = new \Piwik\Application\Environment('tracker');

      try
      {
        $environment->init();
      }
      catch (Throwable $e)
      {
        die($e->getMessage());
      }
    }

    /**
     * Update Matomo components
     */
    public function updateComponents() : void
    {
      echo "Updating components...\n";
      Access::getInstance();

      $updateComponentsCallback = function()
      {
        $pluginManager = Manager::getInstance();
        $pluginManager->loadPluginTranslations();
        $pluginManager->loadActivatedPlugins();
        $pluginManager->installLoadedPlugins();
        $updater = new Updater();

        try
        {
          $componentsWithUpdateFile = $updater->getComponentUpdates();
        }
        catch (Throwable $exception)
        {
          echo "Failed to get component updates.\n";
        }

        if (empty($componentsWithUpdateFile))
        {
          return;
        }

        try
        {
          $updater->updateComponents($componentsWithUpdateFile);
        }
        catch (Throwable $exception)
        {
          echo "Failed to update components.\n";
        }
      };

      Access::doAsSuperUser($updateComponentsCallback);
    }

    /**
     * creates tables for Matomo
     * if it's a reset, we need to drop tables before creation and also create a new user.
     */
    private function setupDb() : void
    {
      if (!Db::hasDatabaseObject() && DbHelper::isValidDbname($this->aOptions['b']))
      {
        echo "Setting up DB...\n";
        $dbInfos = [
          'host'          => $this->aOptions['d'],
          'username'      => $this->aOptions['e'],
          'password'      => $this->aOptions['f'],
          'dbname'        => null,
          'tables_prefix' => $this->aOptions['g'],
          'adapter'       => $this->aOptions['h'],
          'port'          => $this->aOptions['i'],
          'schema'        => $this->aOptions['j'],
          'type'          => $this->aOptions['k'],
          'enable_ssl'    => $this->aOptions['l'],
          'ssl_cipher'    => $this->aOptions['m'],
          'ssl_no_verify' => $this->aOptions['n']
        ];

        Db::createDatabaseObject($dbInfos);
        DbHelper::createDatabase($this->aOptions['b']);
        $dbInfos['dbname'] = $this->aOptions['b'];
        Db::createDatabaseObject($dbInfos);
      }

      $bTablesInstalled = (count(DbHelper::getTablesInstalled()) > 0);

      //reset data
      if ($this->aOptions['s'] === '1' && $bTablesInstalled)
      {
        try
        {
          Db::dropAllTables();
        }
        catch (Throwable $exception)
        {
          echo $exception->getMessage() . "\n";
        }
      }

      //create new tables and an anonymous user
      echo "Creating any tables that do not already exist...\n";
      DbHelper::createTables();

      if ($this->aOptions['s'] === '1' || !$bTablesInstalled)
      {
        try
        {
          echo "Creating a new user...\n";
          //create anonymous user account
          DbHelper::createAnonymousUser();

          //set up variables to help with user creation
          $oUserModel = new Piwik\Plugins\UsersManager\Model();
          $oAccess = Access::getInstance();
          $oAccess->setSuperUserAccess(true);
          $oUserAccessFilter = new Piwik\Plugins\UsersManager\UserAccessFilter($oUserModel, $oAccess);
          $oPassword = new Password();

          //create an admin user
          $oUserAPI = new Piwik\Plugins\UsersManager\API($oUserModel, $oUserAccessFilter, $oPassword);
          $sPassword = $this->aOptions['p'];
          $sLogin = $this->aOptions['o'];

          try
          {
            $oUserAPI->addUser($sLogin, $sPassword, $this->aOptions['q']);
          }
          catch (Throwable $exception)
          {
            // have to catch this to plugin error to prevent the add user process from failing
            echo "Caught Exception (this is expected) - " . $exception->getMessage() . "\n";
          }

          $oUserAPI->setSuperUserAccess($sLogin, true);
          $sAuthToken = $oUserAPI->createAppSpecificTokenAuth($sLogin, $sPassword, $this->aOptions['r']);
          echo "\nAUTH TOKEN: " . $sAuthToken . "\n\n";
        }
        catch (Throwable $exception)
        {
          echo "Caught Exception (this is NOT expected) - " . $exception->getMessage() . "\n";
        }

        ServerFilesGenerator::createFilesForSecurity();

        //add the current site for tracking
        APISitesManager::getInstance()->addSite($this->aOptions['c'], 'https://' . $this->aOptions['c']);
      }
      else
      {
        echo "\n NO AUTH TOKEN RETURNED\n\n";
      }
    }
  }

  try
  {
    $oMatomoUtil = new MatomoUtil();
    $oMatomoUtil->execute();
  }
  catch (Throwable $e)
  {
    echo $e->getMessage();
  }