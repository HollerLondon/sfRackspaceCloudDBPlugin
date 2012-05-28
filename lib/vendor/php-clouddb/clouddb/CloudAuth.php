<?php
/**
 * Rackspace Authentication response model
 *
 * @author Sergei Krot <sergey.krot@holler.co.uk>
 */
class CloudAuth
{
    public $tokenId, $tokenExpires;
    public $serviceCatalog;

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
        $this->tokenId = $response['id'];
        if (!empty($response['expires'])) {
            $this->tokenExpires = $response['expires'];
        }
    }
}
