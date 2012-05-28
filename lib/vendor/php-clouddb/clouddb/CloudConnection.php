<?php

/**
 * Connection handler for Rackspace
 *
 * @author Sergei Krot <sergey.krot@holler.co.uk>
 */
class CloudConnection
{
    /* @var $accountId string, $serviceUrl string, $authUrl string*/
    public $accountId, $serviceUrl, $authUrl;
    /* @var $auth CloudAuth */
    public $auth;

    /* @var $curl resource */
    protected $curl;

    /**
     * @var array This is public and has some defaults. Feel free to add extras if curl fails for you
     */
    public static $CURL_OPTS = array(
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'holler-php-clouddb',
    );

    /**
     * @param string $accountId Rackspace account id
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
     * Run authentication with provided credentials
     *
     * @param $username
     * @param $key
     * @return CloudAuth
     */
    public function authenticate($username, $key)
    {
        // make the payload
        $params = new stdClass();
        $params->credentials = new stdClass();
        $params->credentials->username = $username;
        $params->credentials->key = $key;

        $response = $this->sendRequest($this->authUrl . '/auth', $params, 'POST');
        $this->auth = new CloudAuth($response['auth']['token']);

        return $this->auth;
    }

    /**
     * Perform a POST on specified endpoint. Please note that the endpoint is relative
     * i.e. you don't need to include full URI with your account id, just whatever comes after it
     *
     *
     * @param string $endpoint Endpoint to access eg. /instances/detail
     * @param array|stdClass $data Whatever you want to post. Usually this is an array or stdClass which will be sent as JSON
     * @return array json decoded array
     */
    public function post($endpoint, $data=null)
    {
        $fullEndpoint = $this->serviceUrl . $endpoint;
        return $this->sendRequest($fullEndpoint, $data, 'POST');
    }

    /**
     * Perform a GET on specified endpoint. Please note that the endpoint is relative
     * i.e. you don't need to include full URI with your account id, just whatever comes after it
     *
     * @param string $endpoint Endpoint to access eg. /instances/detail
     * @return array json decoded array
     */
    public function get($endpoint)
    {
        $fullEndpoint = $this->serviceUrl . $endpoint;
        return $this->sendRequest($fullEndpoint, null, 'GET');
    }

    /**
     * Do the curly http thingy.
     * In theory works with GET|POST|DELETE but for now there is no delete.
     *
     * @param string $endpoint Endpoint url
     * @param mixed $data Stuff you want to send with request. Goes as json_encode for POST or query string for GET
     * @param string $type GET|POST|DELETE
     *
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
