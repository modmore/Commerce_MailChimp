<?php
namespace modmore\Commerce_MailChimp\Fields;
use modmore\Commerce\Order\Field\AbstractField;
use modmore\Commerce_MailChimp\Guzzler\MailChimpGuzzler;

/**
 * Class MailChimpSubscriptionField
 *
 * Renders a view of the subscribed MailChimp user.
 * @package modmore\Commerce_MailChimp\Fields
 */
class MailChimpSubscriptionField extends AbstractField {
    protected $guzzler;

    public function loadMailChimpData($apiKey) {
        $this->guzzler = new MailChimpGuzzler($this->commerce,$apiKey);
    }

    public function renderForAdmin() {
        return 'test!';
    }
}