<?php

// since we require curl, check for it
if (!function_exists('curl_init')) {
    throw new Exception('This library requires CURL PHP extension to work.');
}

// json is also handy
if (!function_exists('json_decode')) {
    throw new Exception('This library requires JSON PHP extension to work.');
}

/**
 * A quick library to perform some actions with Rackspace CLoudDB
 *
 * @author Sergei Krot <sergey.krot@holler.co.uk>
 */
class RackspaceCloudDB
{
    const VERSION = '0.2';
    /* @var $connection CloudConnection */
    protected $connection;

    /**
     * @param $accountId
     * @param string $geo
     */
    public function __construct($accountId, $geo = 'LON')
    {
        $this->connection = new CloudConnection($accountId, $geo);
    }

    /**
     * @param $username
     * @param $key
     * @return CloudAuth
     */
    public function authenticate($username, $key)
    {
        return $this->connection->authenticate($username, $key);
    }

    /**
     * Get all instances for current account
     * @return CloudInstance[]
     */
    public function getInstances()
    {
        $instances = array();
        $response = $this->connection->get('/instances/detail');

        // create bunch of instances
        if (!empty($response['instances'])) {
            foreach($response['instances'] as $k => $v) {
                $instances[] = new CloudInstance($v);
            }
        }

        return $instances;
    }

    /**
     * @param $instanceId
     * @return CloudInstance
     */
    public function getInstance($instanceId)
    {
        $response = $this->connection->get('/instances/' . $instanceId);

        return new CloudInstance($response);
    }

    /**
     * @param $instanceId
     * @return CloudDatabase[]
     */
    public function getDatabases($instanceId)
    {
        $databases = array();
        $response = $this->connection->get('/instances/' . $instanceId . '/databases');

        if (!empty($response['databases'])) {
            foreach ($response['databases'] as $database) {
                $databases[] = new CloudDatabase($database);
            }
        }

        return $databases;
    }

    /**
     *
     * @param $instanceId
     * @param null $databaseName
     * @return CloudUser[]
     */
    public function getUsers($instanceId, $databaseName = null)
    {
        $users = array();
        $response = $this->connection->get('/instances/' . $instanceId . '/users');

        if (!empty($response['users'])) {
            foreach ($response['users'] as $u) {
                $user = new CloudUser($u);

                if ($databaseName === null || $user->canAccessDatabase($databaseName)) {
                    $users[] = $user;
                }
            }
        }

        return $users;
    }

    /**
     * Define a new clouddb server instance.
     * You can supply an array of databases as well as users to avoid extra http calls
     *
     * @param CloudInstance $instance
     * @param array $dbs
     * @param array $usrs
     * @return CloudInstance
     */
    public function addInstance(CloudInstance $instance, $dbs=array(), $usrs = array())
    {
        // add flavorRef in case its missing
        if (empty($instance->flavorRef) && !empty($instance->flavorId)) {
            $instance->flavorRef = $this->connection->serviceUrl . '/flavors/' . $instance->flavorId;
        }

        // get data for JSONing
        $data = $instance->add($dbs, $usrs);
        $result = $this->connection->post('/instances', $data);

        return new CloudInstance($result['instance']);
    }

    /**
     * Push a new database to the clouddb server instance
     *
     * @param $instanceId
     * @param CloudDatabase $database
     * @return CloudDatabase
     */
    public function addDatabase($instanceId, CloudDatabase $database)
    {
        $data = $database->add();
        $result = $this->connection->post('/instances/' . $instanceId . '/databases', $data);

        return $database;
    }

    /**
     * Push given user to the clouddb server instance
     *
     * @param $instanceId
     * @param CloudUser $user
     * @return CloudUser
     */
    public function addUser($instanceId, CloudUser $user)
    {
        $data = $user->add();
        $result = $this->connection->post('/instances/' . $instanceId . '/users', $data);

        return $user;
    }

}