<?php
namespace modmore\Commerce_MailChimp\Fields;
use modmore\Commerce\Exceptions\ViewException;
use modmore\Commerce\Order\Field\AbstractField;
use modmore\Commerce_MailChimp\MailchimpClient;

/**
 * Class MailChimpSubscriptionField
 *
 * Renders a view of the subscribed MailChimp user.
 * @package modmore\Commerce_MailChimp\Fields
 */
class SubscriptionStatus extends AbstractField {
    protected $mailChimpClient;

    /**
     * Function: setSubscriberId
     *
     * Takes the subscriberId and uses the MailChimpGuzzler to generate the correct admin link which is saved as the field value.
     * @param $apiKey
     * @param $subscriberId
     */
    public function setSubscriberId($apiKey,$subscriberId) {
        $this->mailChimpClient = new MailchimpClient($this->commerce,$apiKey);
        $this->value = $this->mailChimpClient->getSubscriberUrl($subscriberId);
    }

    /**
     * Function: renderForAdmin
     *
     * Outputs the subscribed status with a link to the subscriber's page in the MailChimp dashboard.
     * @return string
     */
    public function renderForAdmin() {
        $valueOutput = '<i class="icon check"></i><a title="'.$this->commerce->adapter->lexicon('commerce_mailchimp.order_field.description').'" href="'.$this->value.'" target="_blank">'
            .$this->commerce->adapter->lexicon('commerce_mailchimp.order_field.value.subscribed').'</a>';

        try {
            return $this->commerce->view()->renderString($valueOutput, [
                'name' => $this->name
            ]);
        } catch (ViewException $e) {
            return $this->name . ': ' . $valueOutput;
        }
    }
}