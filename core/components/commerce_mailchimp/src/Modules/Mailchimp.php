<?php

namespace modmore\Commerce_MailChimp\Modules;

use modmore\Commerce\Admin\Widgets\Form\CheckboxField;
use modmore\Commerce\Admin\Widgets\Form\PasswordField;
use modmore\Commerce\Admin\Widgets\Form\SelectField;
use modmore\Commerce\Events\Checkout;
use modmore\Commerce\Events\OrderState;
use modmore\Commerce\Modules\BaseModule;
use modmore\Commerce\Order\Field\Text;
use modmore\Commerce_MailChimp\Fields\MailChimpSubscriptionField;
use modmore\Commerce_MailChimp\Guzzler\MailChimpGuzzler;
use Symfony\Component\EventDispatcher\EventDispatcher;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class Mailchimp extends BaseModule
{

    public function getName()
    {
        $this->adapter->loadLexicon('commerce_mailchimp:default');

        return $this->adapter->lexicon('commerce_mailchimp');
    }

    public function getAuthor()
    {
        return 'modmore';
    }

    public function getDescription()
    {
        return $this->adapter->lexicon('commerce_mailchimp.description');
    }

    public function initialize(EventDispatcher $dispatcher)
    {
        // Load our lexicon
        $this->adapter->loadLexicon('commerce_mailchimp:default');

        // Allow module to run only if configuration is complete.
        if ($this->moduleReady()) {
            // Check for opt-in value at cart, address and payment steps.
            $dispatcher->addListener(\Commerce::EVENT_CHECKOUT_BEFORE_STEP, [$this, 'checkOptIn']);

            // Checks if an email address is available each step and if the user is already subscribed.
            $dispatcher->addListener(\Commerce::EVENT_CHECKOUT_BEFORE_STEP, [$this, 'checkEmailAddress']);

            // Adds a subscribed placeholder to the template each step.
            $dispatcher->addListener(\Commerce::EVENT_CHECKOUT_AFTER_STEP, [$this, 'addPlaceholder']);

            // Subscribes customer to the designated Mailchimp list.
            $dispatcher->addListener(\Commerce::EVENT_STATE_CART_TO_PROCESSING, [$this, 'subscribeCustomer']);
        }
    }

    /**
     * Function: moduleReady
     *
     * Checks if the module's configuration has been completed.
     * @return bool
     */
    public function moduleReady()
    {
        // Check API key
        if (!$this->getConfig('apikey')) {
            $this->adapter->log(MODX_LOG_LEVEL_ERROR, '[Commerce_Mailchimp] Unable to initialize. Missing MailChimp API key.');
            return false;
        }

        // Check list id
        if (!$this->getConfig('listid')) {
            $this->adapter->log(MODX_LOG_LEVEL_ERROR, '[Commerce_Mailchimp] Unable to initialize. Missing MailChimp List ID.');
            return false;
        }

        // Check address type
        $addressType = $this->getConfig('addresstype');
        if (!$addressType || ($addressType !== 'billing' && $addressType !== 'shipping')) {
            $this->adapter->log(MODX_LOG_LEVEL_ERROR, '[Commerce_Mailchimp] Unable to initialize. Address Type invalid. Either billing or shipping should be selected.');
            return false;
        }

        return true;
    }

    /**
     * Function: checkOptIn
     *
     * Checks for a MailChimp opt-in value at three stages during checkout.
     * Adds a flag to the order.
     * @param Checkout $event
     */
    public function checkOptIn(Checkout $event)
    {
        // Ignore steps that are not cart, address or payment.
        switch ($event->getStepKey()) {
            case 'cart':
            case 'address':
            case 'payment':
                $data = $event->getData();
                if ($data['mailchimp_opt_in'] === 'on') {
                    $order = $event->getOrder();
                    $order->setProperty('mailchimp_opt_in', true);
                    $order->save();
                }
        }
    }

    /**
     * Function: checkEmailAddress
     *
     * Each step of checkout, checks for a logged in user and their email address.
     * @param Checkout $event
     */
    public function checkEmailAddress(Checkout $event): void
    {
        $order = $event->getOrder();
        if ($order->getProperty('mailchimp_status') === 'subscribed') {
            return;
        }

        // Check if billing or shipping address should be used.
        $addressType = $this->getConfig('addresstype', 'billing');

        $address = $addressType === 'shipping' ? $order->getShippingAddress() : $order->getBillingAddress();
        $subscriberId = false;
        if ($address instanceof \comOrderAddress) {
            $subscriberId = $this->checkSubscription($address->get('email'));
        }

        // Save subscribed status to be used for placeholder after step, and save subscriber id
        if ($subscriberId) {
            $order->setProperty('mailchimp_status', 'subscribed');
            $order->setProperty('mailchimp_subscriber_id', $subscriberId);
        }
    }

    /**
     * Function: addPlaceholder
     *
     * Adds a placeholder to a template if a customer has subscribed status
     * @param Checkout $event
     */
    public function addPlaceholder(Checkout $event): void
    {
        $order = $event->getOrder();
        if ($order->getProperty('mailchimp_status') === 'subscribed') {
            $data = $event->getData();
            $data['mailchimp_subscribed'] = true;
            $event->setData($data);
        }
    }

    /**
     * Function: checkSubscription
     *
     * Checks if a customer is already subscribed to the MailChimp list.
     * @param $email
     * @return bool
     */
    public function checkSubscription($email): bool
    {
        // Make sure the email is lowercase.
        $email = strtolower($email);

        // Get an md5 hash of the email address
        $hash = md5($email);

        // Get the list id
        $listId = $this->getConfig('listid');

        $guzzler = new MailChimpGuzzler($this->commerce, $this->getConfig('apikey'));
        $subscriberId = $guzzler->checkSubscription($hash, $listId);

        // Return the subscriberId if there is a subscription.
        return $subscriberId ? $subscriberId : false;
    }

    /**
     * Function: subscribeCustomer
     *
     *
     * @param OrderState $event
     */
    public function subscribeCustomer(OrderState $event): void
    {
        $order = $event->getOrder();

        // Don't subscribe customer if they haven't opted in and are not subscribed already
        if (!$order->getProperty('mailchimp_opt_in') && !$order->getProperty('mailchimp_status')) {
            $this->addOrderField($order);

            return;
        }

        // If customer is already subscribed, grab their subscriberId and add the MailChimpSubscriptionField (ignore any opt-in)
        if ($order->getProperty('mailchimp_status')) {
            $this->addOrderField($order, $order->getProperty('mailchimp_subscriber_id'));

            return;
        }

        // Gather customer data for new subscriber
        $addressType = $this->getConfig('addresstype');
        $address = $addressType === 'shipping' ? $order->getShippingAddress() : $order->getBillingAddress();

        if ($address instanceof \comOrderAddress) {
            // Try to get value from 'firstname' field. Otherwise just add 'fullname' as FNAME
            $firstName = $address->get('firstname') ? $address->get('firstname') : $address->get('fullname');

            // Try to get 'lastname'. POST will fail with an empty last name, so add a space if no value.
            $lastName = $address->get('lastname') ? $address->get('lastname') : ' ';

            // If user chose double opt-in, set the status to pending for the new subscription.
            $customerData = [];
            $customerData['email_address'] = $address->get('email');
            $customerData['status'] = $this->getConfig('doubleoptin') ? 'pending' : 'subscribed';
            $customerData['merge_fields']['FNAME'] = $firstName;
            $customerData['merge_fields']['LNAME'] = $lastName;

            $customerDataJSON = json_encode($customerData);

            $guzzler = new MailChimpGuzzler($this->commerce, $this->getConfig('apikey'));
            $result = $guzzler->subscribeCustomer($this->getConfig('listid'), $customerDataJSON);

            // Add order field for the new subscriber
            $this->addOrderField($order, $result['web_id']);

        }

    }

    /**
     * Function: addOrderField
     *
     * Adds a field to the order so the admin can see if the customer is subscribed or not.
     * Pass in a MailChimp subscriberId to show the MailChimpSubscriptionField on the order.
     * @param \comOrder $order
     * @param null $subscriberId
     */
    public function addOrderField(\comOrder $order, $subscriberId = null): void
    {
        if ($subscriberId) {
            $field = new MailChimpSubscriptionField($this->commerce, 'mailchimp_field.subscribe', true);
            if ($subscriberId) {
                $field->setSubscriberId($this->getConfig('apikey'), $subscriberId);
            }
        } else {
            // Add a plain text field showing the customer is not subscribed.
            $field = new Text($this->commerce, 'mailchimp_field.not_subscribed', $this->adapter->lexicon('commerce_mailchimp.order_field.value.not_subscribed'));
        }
        $order->setOrderField($field);

    }

    public function getModuleConfiguration(\comModule $module)
    {
        $apiKey = $module->getProperty('apikey', '');

        $fields = [];
        $fields[] = new PasswordField($this->commerce, [
            'name' => 'properties[apikey]',
            'label' => $this->adapter->lexicon('commerce_mailchimp.api_key'),
            'description' => $this->adapter->lexicon('commerce_mailchimp.api_key.description'),
            'value' => $apiKey
        ]);

        // On saving the module config modal, the form will reload adding the extra fields once an API key has been added.
        if ($apiKey !== '') {
            $guzzler = new MailChimpGuzzler($this->commerce, $apiKey);
            $lists = $guzzler->getLists();

            if (!$lists) {
                return $fields;
            }

            // Select field for MailChimp lists
            $fields[] = new SelectField($this->commerce, [
                'name' => 'properties[listid]',
                'label' => $this->adapter->lexicon('commerce_mailchimp.list'),
                'description' => $this->adapter->lexicon('commerce_mailchimp.list.description'),
                'value' => $module->getProperty('listid', ''),
                'options' => $lists
            ]);

            // Select field for type of address to be submitted (billing or shipping)
            $fields[] = new SelectField($this->commerce, [
                'name' => 'properties[addresstype]',
                'label' => $this->adapter->lexicon('commerce_mailchimp.address_type'),
                'description' => $this->adapter->lexicon('commerce_mailchimp.address_type.description'),
                'value' => $module->getProperty('listid', 'billing'),
                'options' => [
                    [
                        'value' => 'billing',
                        'label' => $this->adapter->lexicon('commerce_mailchimp.address_type.billing')
                    ],
                    [
                        'value' => 'shipping',
                        'label' => $this->adapter->lexicon('commerce_mailchimp.address_type.shipping')
                    ]
                ]
            ]);

            // Checkbox to enable double opt-in for MailChimp subscriptions.
            $fields[] = new CheckboxField($this->commerce, [
                'name' => 'properties[doubleoptin]',
                'label' => $this->adapter->lexicon('commerce_mailchimp.double_opt_in'),
                'description' => $this->adapter->lexicon('commerce_mailchimp.double_opt_in.description'),
                'value' => $module->getProperty('doubleoptin', '')
            ]);
        }

        return $fields;
    }
}
