<?php
/**
 * User: stonedz
 * Date: 2/8/15
 * Time: 5:06 PM
 */

namespace pff\modules;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use pff\Core\ServiceContainer;
use pff\Iface\IBeforeSystemHook;
use pff\Iface\IBeforeViewHook;
use pff\Iface\IConfigurableModule;

class Pff2DbSession extends AModule implements IConfigurableModule, IBeforeSystemHook{

    /**
     * @var EntityManager
     */
    private $db;

    private $modelName;

    public function __construct($confFile = 'pff2-dbsession/module.conf.local.yaml') {
        $this->loadConfig($confFile);


        $this->db = ServiceContainer::get('dm');
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
        session_set_save_handler(
            array($this, "_open"),
            array($this, "_close"),
            array($this, "_read"),
            array($this, "_write"),
            array($this, "_destroy"),
            array($this, "_gc")
        );
        session_start();
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
            try {
                $this->db->flush();
                return true;
            }
            catch(\Exception $e) {
               return false;
            }
        }
        else {
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
            ->from($this->modelName, 's')
            ->where('s.access < :old')
            ->setParameters(array('old' => $old));

        $res = $qb->getQuery()->getArrayResult();

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
            return true;
        }
        else {
            return true;
        }
    }
}