<?php
namespace modmore\Commerce_MailChimp\Guzzler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Class MailChimpGuzzler
 *
 * Guzzle client for the Commerce module Commerce_MailChimp
 */
class MailChimpGuzzler {
    protected $adapter;
    protected $apiKey;
    protected $urlScheme = 'https://';
    protected $httpHost = 'api.mailchimp.com';
    protected $apiUrl;
    protected $apiVersion = '3.0';

    public function __construct(&$adapter,$apiKey) {
        // TODO: Verify API Key (or perhaps not as it would mean an extra request)
        $this->apiKey = $apiKey;
        $this->adapter = $adapter;
        $dataCenterCode = $this->findDataCenterCode();

        // Form API URL with newly found data-center code.
        $this->apiUrl = $this->urlScheme.$dataCenterCode.'.'.$this->httpHost.'/'.$this->apiVersion.'/';
    }

    /**
     * Function: findDataCenterCode
     *
     * Mailchimp's API URL is dependent on the data-center code at the end of an assigned API key.
     * This function grabs the API key and returns the final portion delimited by a hyphen.
     *
     * @return bool|mixed
     */
    protected function findDataCenterCode() {
        if(!$this->apiKey) return false;
        $exploded = explode('-',$this->apiKey);
        // Check length of array in case api key format changes in the future.
        $size = count($exploded);
        // Return the last array value.
        return $exploded[$size - 1];
    }

    /**
     *
     */
    public function getLists() {
        $client = new Client();
        try {
            $res = $client->request('GET', $this->apiUrl.'lists', [
                'auth'      => ['apikey', $this->apiKey],
            ]);
        } catch(GuzzleException $guzzleException) {
            // TODO: Do something with the exception.
            return false;
        }

        //$this->adapter->log(MODX_LOG_LEVEL_ERROR,$res->getStatusCode());
        // "200"

        $responseArray = json_decode($res->getBody(),true);
        if(!$responseArray) return false;

        $lists = [];
        foreach($responseArray['lists'] as $list) {
            $lists[] = [
                'value' =>  $list['id'],
                'label' =>  $list['name']
            ];
        }
        return $lists;
    }
}