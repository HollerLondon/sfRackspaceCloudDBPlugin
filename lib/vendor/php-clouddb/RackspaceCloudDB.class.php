<?php

// since we require curl, check for it
if (!function_exists('curl_init')) {
    throw new Exception('This library requires CURL PHP extension to work.');
}

if (!function_exists('json_decode')) {
    throw new Exception('This library requires JSON PHP extension to work.');
}

/**
 * A quick library to perform some actions with Rackspace CLoudDB
 *
 */
class RackspaceCloudDB
{
    const VERSION = '0.1';

    public static $CURL_OPTS = array(
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'holler-clouddb 0.1',
    );

    protected $auth;

    protected $accountId;
    protected $serviceUrl, $authUrl;
    protected $curl;

    /**
     * @param $accountId
     * @param string $geo
     */
    public function __construct($accountId, $geo = 'LON')
    {
        $this->accountId = $accountId;
        $geo = strtolower($geo);

        $this->authUrl = ($geo == 'lon')
            ? 'https://lon.identity.api.rackspacecloud.com/v1.1'
            : 'https://identity.api.rackspacecloud.com/v1.1';

        $this->serviceUrl = 'https://' . $geo . '.databases.api.rackspacecloud.com/v1.0/' . $this->accountId;
    }

    /**
     * @param $username
     * @param $key
     * @return CloudAuth
     */
    public function authenticate($username, $key)
    {
        $params = new stdClass();
        $params->credentials = new stdClass();
        $params->credentials->username = $username;
        $params->credentials->key = $key;

        $response = $this->sendRequest($this->authUrl . '/auth', $params, 'POST');
        $this->auth = new CloudAuth($response);

        return $this->auth;
    }

    /**
     * Get all instances for current account
     * @return CloudInstance[]
     */
    public function getInstances()
    {
        $instances = array();
        $response = $this->sendRequest($this->serviceUrl . '/instances/detail');

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
        $response = $this->sendRequest($this->serviceUrl . '/instances/' . $instanceId);

        return new CloudInstance($response);
    }

    /**
     * @param $instanceId
     * @return CloudDatabase[]
     */
    public function getDatabases($instanceId)
    {
        $databases = array();
        $response = $this->sendRequest($this->serviceUrl . '/instances/' . $instanceId . '/databases');

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
        $response = $this->sendRequest($this->serviceUrl . '/instances/' . $instanceId . '/users');

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
            $instance->flavorRef = $this->serviceUrl . '/flavors/' . $instance->flavorId;
        }

        $data = $instance->add($dbs, $usrs);

        $result = $this->sendRequest($this->serviceUrl . '/instances', $data, 'POST');

        return new CloudInstance($result['instance']);
    }

    /**
     * Push a new databse to the clouddb server instance
     *
     * @param $instanceId
     * @param CloudDatabase $database
     * @return CloudDatabase
     */
    public function addDatabase($instanceId, CloudDatabase $database)
    {
        $result = $this->sendRequest($this->serviceUrl . '/instances/' . $instanceId . '/databases', $database->add(), 'POST');

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
        $result = $this->sendRequest($this->serviceUrl . '/instances/' . $instanceId . '/users', $data, 'POST');

        return $user;
    }

    /**
     * Do the curly http thingy.
     * In theory works with GET|POST|DELETE but for now there is no delete.
     *
     * @param string $endpoint Complete endpoint url
     * @param mixed $data Stuff you want to send with request. Goes as json_encode for POST or query string for GET
     * @param string $type GET|POST|DELETE
     *
     * @throws CloudBadRequestException
     * @throws ErrorException
     * @return array json decoded array
     *
     */
    protected function sendRequest($endpoint, $data = null, $type = 'GET')
    {
        $this->curl = curl_init();
        $options = self::$CURL_OPTS;

        if ($type === 'GET') {
            // do query string conversion for data
            //$query = http_build_query($data);
            // we should probably add that to the url but there are no endpoints for get with params
        } elseif ($type === 'POST') {
            // set post headers
            $options[CURLOPT_POST] = 1;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        } else {
            throw new CloudBadRequestException('We only support POST and GET');
        }

        $options[CURLOPT_URL] = $endpoint;
        $headers = (empty($options[CURLOPT_HTTPHEADER])) ? array() : $options[CURLOPT_HTTPHEADER];

        // if we got auth done, include it
        if (!empty($this->auth)) {
            $headers[] = sprintf('X-Auth-Token: %s', $this->auth->tokenId);
        }

        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($this->curl, $options);
        $result = curl_exec($this->curl);

//        echo "\n ---------------debug---------------\nHTTP Response:\n$result \n\n";
//        $this->debugCurl($this->curl);
//        echo "------------------------------------\n";

        // if there was a curl error lets just bail
        if ($result === false) {
            echo "\n ---------------debug---------------\nHTTP Response:\n$result \n\n";
            $this->debugCurl($this->curl);
            echo "------------------------------------\n";

            throw new ErrorException('Curl error: ' . curl_error($this->curl));
        }

        // throw exceptions if needed
        return $this->checkResponse($result);
    }

    /**
     * Throw an exception if there is an error of some sort
     * @param string $response
     *
     * @return array
     * @throws Exception
     */
    protected function checkResponse($response)
    {
        $result = json_decode($response, true);

        // if there is response but no json, something isn't right
        if (empty($result) && !empty($response)) {
            $code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
            curl_close($this->curl);

            echo "\n ---------------debug---------------\nHTTP Response:\n$response \n\n";
            $this->debugCurl($this->curl);
            echo "------------------------------------\n";
            curl_close($this->curl);

            throw new Exception("There was an error during http request. HTTP code: $code");
        }

        // not ideal but should kinda work
        if (!empty($result)) {
            $errorType = key($result);
            $errorClass = 'Cloud' . ucfirst($errorType) . 'Exception';
            if (class_exists($errorClass)) {
                echo "\n ---------------debug---------------\nHTTP Response:\n$response \n\n";
                $this->debugCurl($this->curl);
                echo "------------------------------------\n";
                curl_close($this->curl);

                throw new $errorClass($result[$errorType]['message'], $result[$errorType]['code']);
            }
        }

        curl_close($this->curl);

        return $result;
    }

    /**
     * Output some debugging info about curl instance
     *
     * @param resource $ch
     */
    protected function debugCurl($ch)
    {
        if (!curl_errno($ch)) {
            $info = curl_getinfo($ch);
            echo sprintf("Took %s seconds to receive  %s bytes from %s \n", $info['total_time'], $info['download_content_length'], $info['url']);
        } else {
            echo 'Curl error: ' . curl_error($ch);
        }
    }
}