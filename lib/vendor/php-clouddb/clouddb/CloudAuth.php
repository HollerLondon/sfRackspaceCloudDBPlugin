<?php
/**
 * Rackspace Authentication token
 */
class CloudAuth
{
    public $tokenId, $tokenExpires;

    /**
     * @param array $response
     */
    public function __construct($response = array())
    {
        if (!empty($response)) {
            $this->fromArray($response);
        }
    }

    /**
     * @param $response
     */
    public function fromArray(array $response)
    {
        $this->tokenId = $response['auth']['token']['id'];
        $this->tokenExpires = $response['auth']['token']['expires'];
    }
}
