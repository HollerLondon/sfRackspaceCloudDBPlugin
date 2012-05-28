<?php
/**
 * Rackspace CloudDB database server instance entity
 *
 * @author Sergei Krot <sergey.krot@holler.co.uk>
 */
class CloudInstance
{
    public $id, $name, $status, $flavorId, $flavorRef, $hostname, $volumeSize, $created, $updated, $links, $flavor;

    /**
     * @param array $response
     */
    public function __construct(array $response = array())
    {
        if (!empty($response)) {
            $this->fromArray($response);
        }
    }

    /**
     * @param array $response
     */
    public function fromArray(array $response)
    {
        // get all public props
        $vars = get_class_vars(__CLASS__);
        
        foreach ($vars as $name => $value) {
            if (isset($response[$name])) {
                $this->$name = $response[$name];
            }
        }

        if (!empty($this->flavor)) {
            $this->flavorId = $this->flavor['id'];
            $this->flavorRef = $this->flavor['links'][0]['href'];
        }
    }


    /**
     * Add current instance as new instance to db
     * For now it just returns object used as data for the POST
     *
     * @param CloudDatabase[] $dbs
     * @param CloudUser[] $usrs
     *
     * @return stdClass
     */
    public function add($dbs=array(), $usrs = array())
    {
        $instance = new stdClass();

        if ($this->name) {
            $instance->name = $this->name;
        }

        $instance->flavorRef = $this->flavorRef;

        $instance->volume = new stdClass();
        $instance->volume->size = $this->volumeSize;

        if (!empty($dbs)) {
            $databases = array();
            foreach($dbs as $db) {
                $databases[] = $db->add()->databases[0];
            }

            if (!empty($databases)) {
                $instance->databases = $databases;
            }
        }

        if (!empty($usrs)) {
            $users = array();
            foreach($usrs as $user) {
                $users[] = $user->add()->users[0];
            }

            if (!empty($users)) {
                $instance->users = $users;
            }
        }

        $data = new stdClass();
        $data->instance = $instance;

        return $data;
    }

    /**
     * http://docs.rackspace.com/cdb/api/v1.0/cdb-devguide/content/POST_resizeInstance__version___accountID__instances__instanceId__action_Database_Instances_Actions.html
     * @param $flavorRef
     */
    public function changeFlavor($flavorRef)
    {

    }

    /**
     * http://docs.rackspace.com/cdb/api/v1.0/cdb-devguide/content/POST_resizeVolume__version___accountID__instances__instanceId__action_Database_Instances_Actions.html
     * @param $newSize
     */
    public function resize($newSize)
    {

    }

    /**
     * http://docs.rackspace.com/cdb/api/v1.0/cdb-devguide/content/POST_restartInstance__version___accountID__instances__instanceId__action_Database_Instances_Actions.html
     */
    public function restart()
    {

    }

    /**
     * http://docs.rackspace.com/cdb/api/v1.0/cdb-devguide/content/DELETE_deleteInstance__version___accountID__instances__instanceId__Database_Instances.html
     */
    public function delete()
    {

    }

    /**
     * Enables root user for the instance
     * http://docs.rackspace.com/cdb/api/v1.0/cdb-devguide/content/POST_createRoot__version___accountID__instances__instanceId__root_Database_Instances.html
     *
     */
    public function enableRootUser()
    {

    }

    /**
     * Get root user details
     * http://docs.rackspace.com/cdb/api/v1.0/cdb-devguide/content/GET_isRootEnabled__version___accountID__instances__instanceId__root_Database_Instances.html
     *
     */
    public function getRootUser()
    {

    }

}
