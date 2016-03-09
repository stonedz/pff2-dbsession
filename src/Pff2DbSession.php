<?php
/**
 * User: stonedz
 * Date: 2/8/15
 * Time: 5:06 PM
 */

namespace pff\modules;

use Doctrine\Common\Cache\ApcuCache;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use pff\Abs\AModule;
use pff\Core\ServiceContainer;
use pff\Iface\IBeforeHook;
use pff\Iface\IBeforeSystemHook;
use pff\Iface\IBeforeViewHook;
use pff\Iface\IConfigurableModule;
use Doctrine\ORM\Configuration;

class Pff2DbSession extends AModule implements IConfigurableModule, IBeforeSystemHook, IBeforeHook{

    /**
     * @var EntityManager
     */
    private $db;

    private $modelName;

    public function __construct($confFile = 'pff2-dbsession/module.conf.local.yaml') {
        $this->loadConfig($confFile);


    }

    /**
     * @param array $parsedConfig
     * @return mixed
     */
    public function loadConfig($parsedConfig) {
        $conf = $this->readConfig($parsedConfig);
        $this->modelName = $conf['moduleConf']['sessionModel'];
    }

    /**
     * Executed before the system startup
     *
     * @return mixed
     */
    public function doBeforeSystem() {
        $this->initORM();
        session_set_save_handler(
            array($this, "_open"),
            array($this, "_close"),
            array($this, "_read"),
            array($this, "_write"),
            array($this, "_destroy"),
            array($this, "_gc")
        );
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function initORM() {
        $config_pff = ServiceContainer::get('config');
        if (true === $config_pff->getConfigData('development_environment')) {
            $cache = new ArrayCache();
        } else {
            $cache = new ApcuCache();
            $cache->setNamespace($this->_app->getConfig()->getConfigData('app_name'));
        }

        $config = new Configuration();
        $config->setMetadataCacheImpl($cache);
        $driverImpl = $config->newDefaultAnnotationDriver(ROOT . DS . 'app' . DS . 'models');
        $config->setMetadataDriverImpl($driverImpl);
        $config->setQueryCacheImpl($cache);
        $config->setProxyDir(ROOT . DS . 'app' . DS . 'proxies');
        $config->setProxyNamespace('pff\proxies');

        if (true === $config_pff->getConfigData('development_environment')) {
            $config->setAutoGenerateProxyClasses(true);
            $connectionOptions = $config_pff->getConfigData('databaseConfigDev');
        } else {
            $config->setAutoGenerateProxyClasses(false);
            $connectionOptions = $config_pff->getConfigData('databaseConfig');
        }


        $this->db= EntityManager::create($connectionOptions, $config);

        ServiceContainer::set()['dm'] = $this->db;
        $platform = $this->db->getConnection()->getDatabasePlatform();
        $platform->registerDoctrineTypeMapping('enum', 'string');
    }

    public function _open() {
        if($this->db) {
            return true;
        }
        else {
            return false;
        }
    }

    public function _close() {
        return true;
    }

    public function _read($id) {
        $res = $this->db->find('\pff\models\\'.$this->modelName,$id);
        if($res) {
            return $res->getData();
        }
        else {
            return '';
        }
    }

    public function _write($id, $data) {
        $access = time();
        $res = $this->db->find('\pff\models\\'.$this->modelName,$id);
        if ($res) {
            $res->setData($data);
            $res->setAccess($access);
        }
        else {
            $session_model = '\pff\models\\'.$this->modelName;
            $session = new $session_model;
            $session->setId($id);
            $this->db->persist($session);
            $session->setData($data);
            $session->setAccess($access);
        }
        try {
            $this->db->flush();
            return true;
        }
        catch(\Exception $e) {
            return false;
        }
    }

    public function _destroy($id) {
        $res = $this->db->find('\pff\models\\'.$this->modelName,$id);
        $this->db->remove($res);
        try {
            $this->db->flush();
            return true;
        }
        catch(\Exception $e) {
            return false;
        }
    }

    public function _gc($max) {
        $old = time() - $max;

        /** @var QueryBuilder $qb */
        $qb = $this->db->createQueryBuilder();
        $qb->select('s')
            ->from('\pff\models\\'.$this->modelName, 's')
            ->where('s.access < :old')
            ->setParameters(array('old' => $old));

        $res = $qb->getQuery()->getResult();

        if(!empty($res)) {
            $count = 1;
            foreach($res as $r) {
                $this->db->remove($r);
                if(($count % 20) == 0 ){
                    try {
                        $this->db->flush();
                    }
                    catch(\Exception $e) {
                        return false;
                    }
                }
                $count++;
            }
            try {
                $this->db->flush();
            }
            catch(\Exception $e) {
                return false;
            }
            return true;
        }
        else {
            return true;
        }
    }

    /**
     * Executes actions before the Controller
     *
     * @return mixed
     */
    public function doBefore() {
        $this->db = ServiceContainer::get('dm');
        // TODO: Implement doBefore() method.
    }
}