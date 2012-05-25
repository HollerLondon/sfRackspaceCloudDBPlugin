<?php
/**
 * Rackspace CloudDB database isntance
 */
class CloudDatabase
{

    public $name, $characterSet, $collate;

    /**
     * @param array $response
     */
    public function __construct($response=array())
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
        $this->name = $response['name'];

        if (!empty($response['character_set'])) {
            $this->characterSet = $response['character_set'];
        }

        if (!empty($response['collate'])) {
            $this->collate = $response['collate'];
        }
    }

    /**
     * @return stdClass
     */
    public function add()
    {
        $db = new stdClass();
        $db->name = $this->name;

        if (!empty($this->characterSet)) {
            $db->character_set = $this->characterSet;
        }

        if (!empty($this->collate)) {
            $db->collate = $this->collate;
        }

        $data = new stdClass();
        $data->databases = array($db);

        return $data;
    }
}
