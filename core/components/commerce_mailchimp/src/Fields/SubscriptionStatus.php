<?php
namespace modmore\Commerce_MailChimp\Fields;
use modmore\Commerce\Order\Field\AbstractField;
use modmore\Commerce_MailChimp\MailchimpClient;

/**
 * Class MailChimpSubscriptionField
 *
 * Renders a view of the subscribed MailChimp user.
 * @package modmore\Commerce_MailChimp\Fields
 */
class SubscriptionStatus extends AbstractField {
    protected $guzzler;

    /**
     * Function: setSubscriberId
     *
     * Takes the subscriberId and uses the MailChimpGuzzler to generate the correct admin link which is saved as the field value.
     * @param $apiKey
     * @param $subscriberId
     */
    public function setSubscriberId($apiKey,$subscriberId) {
        $this->guzzler = new MailchimpClient($this->commerce,$apiKey);
        $this->value = $this->guzzler->getSubscriberUrl($subscriberId);
    }

    /**
     * Function: renderForAdmin
     *
     * Outputs the subscribed status with a link to the subscriber's page in the MailChimp dashboard.
     * @return string
     */
    public function renderForAdmin() {
        return '<i class="icon check"></i><a title="'.$this->commerce->adapter->lexicon('commerce_mailchimp.order_field.description').'" href="'.$this->value.'" target="_blank">'
            .$this->commerce->adapter->lexicon('commerce_mailchimp.order_field.value.subscribed').'</a>';
    }
}