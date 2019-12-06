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
    protected $commerce;
    protected $apiKey;
    protected $urlScheme = 'https://';
    protected $apiHost = 'api.mailchimp.com';
    protected $adminHost = 'admin.mailchimp.com';
    protected $adminUri = 'lists/members/view';
    protected $apiUrl;
    protected $apiVersion = '3.0';
    protected $subscriberUrl;

    public function __construct($commerce,$apiKey) {
        $this->apiKey = $apiKey;
        $this->commerce = $commerce;
        $dataCenterCode = $this->findDataCenterCode();

        // Form API URL with newly found data-center code.
        $this->apiUrl = $this->urlScheme.$dataCenterCode.'.'.$this->apiHost.'/'.$this->apiVersion.'/';

        // Form Subscriber URL using data-center code.
        $this->subscriberUrl = $this->urlScheme.$dataCenterCode.'.'.$this->adminHost.'/'.$this->adminUri;
    }

    /**
     * Function: findDataCenterCode
     *
     * Mailchimp's API URL is dependent on the data-center code at the end of an assigned API key.
     * This function grabs the API key and returns the final portion delimited by a hyphen.
     * @return bool|mixed
     */
    public function findDataCenterCode() {
        if(!$this->apiKey) return false;
        $exploded = explode('-',$this->apiKey);
        // Check length of array in case api key format changes in the future.
        $size = count($exploded);
        // Return the last array value.
        return $exploded[$size - 1];
    }

    public function getSubscriberUrl($subscriberId) {
        return $this->subscriberUrl.'?id='.$subscriberId;
    }

    public function subscribeCustomer($listId,$json) {
        $client = new Client();
        try {
            $res = $client->request('POST', $this->apiUrl.'lists/'.$listId.'/members/', [
                'auth'      =>  ['apikey', $this->apiKey],
                'body'      =>  $json
            ]);
        } catch(GuzzleException $guzzleException) {
            $this->commerce->adapter->log(MODX_LOG_LEVEL_ERROR, $guzzleException->getMessage());
            return false;
        }

        $responseArray = json_decode($res->getBody(),true);
        if(!$responseArray) return false;
        $this->commerce->modx->log(MODX_LOG_LEVEL_ERROR,print_r($responseArray,true));

        return '';
    }

    /**
     * Function: getLists
     *
     * Returns an array of lists in assigned MailChimp account.
     * Array is formatted for the standard Commerce select field.
     * @return array|bool
     */
    public function getLists() {
        $client = new Client();
        try {
            $res = $client->request('GET', $this->apiUrl.'lists', [
                'auth'      => ['apikey', $this->apiKey],
            ]);
        } catch(GuzzleException $guzzleException) {
            $this->commerce->adapter->log(MODX_LOG_LEVEL_ERROR, $guzzleException->getMessage());
            return false;
        }

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


    /**
     * Function: checkSubscription
     *
     * Checks if a customer is already subscribed to the MailChimp list or not.
     * Returns a simple true or false.
     * Requires the Mailchimp list id and an MD5 hash of the customer's email address (lowercase).
     * @param $hash
     * @param $listId
     * @return bool
     */
    public function checkSubscription($hash,$listId) {
        $client = new Client();
        try {
            $res = $client->request('GET', $this->apiUrl.'lists/'.$listId.'/members/'.$hash, [
                'auth'      => ['apikey', $this->apiKey],
            ]);
        } catch(GuzzleException $guzzleException) {
            // 404 status code means the customer is not subscribed - this is normal behaviour for MailChimp.
            if($guzzleException->getCode() != '404') {
                $this->commerce->adapter->log(MODX_LOG_LEVEL_ERROR, $guzzleException->getMessage());
            }
            return false;
        }
        $responseArray = json_decode($res->getBody(), true);
        if ($responseArray) {
            return $responseArray['web_id'];
            //$this->commerce->adapter->log(MODX_LOG_LEVEL_ERROR, print_r($responseArray, true));
        } else {
            return false;
        }
    }
}