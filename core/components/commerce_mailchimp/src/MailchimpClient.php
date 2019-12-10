<?php

namespace modmore\Commerce_MailChimp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use modmore\Commerce\Gateways\Helpers\GatewayHelper;

/**
 * Class MailChimpGuzzler
 *
 * Guzzle client for the Commerce module Commerce_MailChimp
 */
class MailchimpClient
{
    protected $commerce;
    protected $apiKey;
    protected $urlScheme = 'https://';
    protected $apiHost = 'api.mailchimp.com';
    protected $adminHost = 'admin.mailchimp.com';
    protected $adminUri = 'lists/members/view';
    protected $apiUrl;
    protected $apiVersion = '3.0';
    protected $subscriberUrl;

    public function __construct(\Commerce $commerce, string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->commerce = $commerce;
        $dataCenterCode = $this->findDataCenterCode();

        // Form API URL with newly found data-center code.
        $this->apiUrl = $this->urlScheme . $dataCenterCode . '.' . $this->apiHost . '/' . $this->apiVersion . '/';

        // Form Subscriber URL using data-center code.
        $this->subscriberUrl = $this->urlScheme . $dataCenterCode . '.' . $this->adminHost . '/' . $this->adminUri;
    }

    /**
     * Function: findDataCenterCode
     *
     * Mailchimp's API URL is dependent on the data-center code at the end of an assigned API key.
     * This function grabs the API key and returns the final portion delimited by a hyphen.
     * @return bool|mixed
     */
    public function findDataCenterCode()
    {
        if (!$this->apiKey) {
            return false;
        }
        $exploded = explode('-', $this->apiKey);
        // Check length of array in case api key format changes in the future.
        $size = count($exploded);

        // Return the last array value.
        return $exploded[$size - 1];
    }

    public function getSubscriberUrl($subscriberId): string
    {
        return $this->subscriberUrl . '?id=' . $subscriberId;
    }


    public function subscribeCustomer(string $listId, \comOrderAddress $address, $doubleOptIn)
    {
        // Try to get the right names
        $firstName = $address->get('firstname');
        $lastName = $address->get('lastname');
        $fullName = $address->get('fullname');
        GatewayHelper::normalizeNames($lastName, $lastName, $fullName);

        // If user chose double opt-in, set the status to pending for the new subscription.
        $customerData = [];
        $customerData['email_address'] = $address->get('email');
        $customerData['status'] = $doubleOptIn ? 'pending' : 'subscribed';
        $customerData['merge_fields']['FNAME'] = $firstName;
        $customerData['merge_fields']['LNAME'] = $lastName;

        $customerDataJSON = json_encode($customerData);

        $client = new Client();
        try {
            $res = $client->request('POST', $this->apiUrl . 'lists/' . $listId . '/members/', [
                'auth' => ['apikey', $this->apiKey],
                'body' => $customerDataJSON
            ]);
        } catch (GuzzleException $guzzleException) {
            $this->commerce->adapter->log(MODX_LOG_LEVEL_ERROR, $guzzleException->getMessage());
            return false;
        }

        $responseArray = json_decode($res->getBody(), true);
        if (!$responseArray) {
            return false;
        }

        return $responseArray;
    }

    /**
     * Function: getLists
     *
     * Returns an array of lists in assigned MailChimp account.
     * Array is formatted for the standard Commerce select field.
     * @return array|bool
     */
    public function getLists()
    {
        $client = new Client();
        try {
            $res = $client->request('GET', $this->apiUrl . 'lists', [
                'auth' => ['apikey', $this->apiKey],
            ]);
        } catch (GuzzleException $guzzleException) {
            $this->commerce->adapter->log(MODX_LOG_LEVEL_ERROR, $guzzleException->getMessage());
            return false;
        }

        $responseArray = json_decode($res->getBody(), true);
        if (!is_array($responseArray)) {
            return false;
        }

        $lists = [];
        foreach ($responseArray['lists'] as $list) {
            $lists[] = [
                'value' => $list['id'],
                'label' => $list['name']
            ];
        }

        return $lists;
    }


    /**
     * Function: checkSubscription
     *
     * Checks if a customer is already subscribed to the MailChimp list or not.
     * Returns a subscriberId or false.
     * Requires the Mailchimp list id and an MD5 hash of the customer's email address (lowercase).
     * @param $email
     * @param $listId
     * @return mixed
     */
    public function checkSubscription($email, $listId)
    {
        // Make sure the email is lowercase.
        $email = strtolower($email);

        // Get an md5 hash of the email address
        $hash = md5($email);

        $client = new Client();
        try {
            $res = $client->request('GET', $this->apiUrl . 'lists/' . $listId . '/members/' . $hash, [
                'auth' => ['apikey', $this->apiKey],
            ]);
        } catch (GuzzleException $guzzleException) {
            // 404 status code means the customer is not subscribed - this is normal behaviour for MailChimp. (Code is returned as integer)
            if ($guzzleException->getCode() !== 404) {
                $this->commerce->adapter->log(MODX_LOG_LEVEL_ERROR, $guzzleException->getMessage());
            }
            return false;
        }
        $responseArray = json_decode($res->getBody(), true);
        if (is_array($responseArray)) {
            return $responseArray['web_id'];
        }

        return false;
    }
}