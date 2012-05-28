<?php

/**
 * Rackspace CloudDB user entity
 *
 * @author Sergei Krot <sergey.krot@holler.co.uk>
 */
class CloudUser
{
    public $name, $databases, $password;

    /**
     * @param array $response
     */
    public function __construct(array $response=array()) {
        $this->databases = array();
        
        if (!empty($response)) {
            $this->fromArray($response);
        }
    }

    /**
     * @param array $response
     */
    public function fromArray(array $response)
    {
        $this->name = $response['name'];

        if (!empty($response['password'])) {
            $this->password = $response['password'];
        }

        if (!empty($response['databases'])) {
            $this->databases = $response['databases'];
        }
    }

    /**
     * @return stdClass
     */
    public function add()
    {
        $user = new stdClass();
        $user->name = $this->name;

        if (!empty($this->password)) {
            $user->password = $this->password;
        }

        if (!empty($this->databases)) {
            $user->databases = array();

            foreach ($this->databases as $db) {
                $dbObj = new stdClass();
                // here is a small trick to support either a list of db names or a more proper array with name=>dbname
                $dbObj->name = (is_array($db)) ? $db['name'] : $db;;
                $user->databases[] = $dbObj;
            }
        }

        $data = new stdClass();
        $data->users = array($user);

        return $data;
    }

    /**
     * @param $databaseName
     */
    public function grantDatabaseAccess($databaseName)
    {
        $dbObj = new stdClass();
        $dbObj->name = $databaseName;
        $this->databases[] = $dbObj;
    }

    /**
     * @param $dbName
     * @return bool
     */
    public function canAccessDatabase($dbName)
    {
        $canDo = false;
        foreach ($this->databases as $db) {
            if ($db['name'] == $dbName) {
                $canDo = true;
                break;
            }
        }

        return $canDo;
    }
}
