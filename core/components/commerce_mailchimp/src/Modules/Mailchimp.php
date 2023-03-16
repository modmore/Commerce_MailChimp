<?php

namespace modmore\Commerce_MailChimp\Modules;

use modmore\Commerce\Admin\Widgets\Form\CheckboxField;
use modmore\Commerce\Admin\Widgets\Form\PasswordField;
use modmore\Commerce\Admin\Widgets\Form\SelectField;
use modmore\Commerce\Events\Checkout;
use modmore\Commerce\Events\OrderState;
use modmore\Commerce\Modules\BaseModule;
use modmore\Commerce\Order\Field\Text;
use modmore\Commerce_MailChimp\Admin\Widgets\Form\MailChimpCheckboxGroupField;
use modmore\Commerce_MailChimp\Fields\SubscriptionStatus;
use modmore\Commerce_MailChimp\MailchimpClient;
use modmore\Commerce\Dispatcher\EventDispatcher;

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

        // Load our template path
        $this->commerce->view()->addTemplatesPath(dirname(__DIR__, 2) . '/templates/');

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
                $data = $event->getDataKey('mailchimp_opt_in');
                if ($data === 'on') {
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

        // Check if billing or shipping address should be used.
        $addressType = $this->getConfig('addresstype', 'billing');
        $address = $addressType === 'shipping' ? $order->getShippingAddress() : $order->getBillingAddress();

        if ($order->getProperty('mailchimp_status') === 'subscribed') {
            // To prevent subscribed status not updating if a user goes back and changes their email address,
            // only return early if the email is still the same. Otherwise, re-check MailChimp subscription and
            // update the order property values below.
            if($address->get('email') === $order->getProperty('mailchimp_email')) {
                return;
            }
        }

        $subscriberId = false;
        if ($address instanceof \comOrderAddress) {
            $subscriberId = $this->checkSubscription($address->get('email'));
            $order->setProperty('mailchimp_email', $address->get('email'));
        }

        // Save subscribed status to be used for placeholder after step, and save subscriber id
        if ($subscriberId) {
            $order->setProperty('mailchimp_status', 'subscribed');
            $order->setProperty('mailchimp_subscriber_id', $subscriberId);
        } else {
            $order->setProperty('mailchimp_status', '');
            $order->setProperty('mailchimp_subscriber_id', '');
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
        $data = $event->getData();
        $data['mailchimp_enabled'] = true;
        $data['mailchimp_subscribed'] = $order->getProperty('mailchimp_status') === 'subscribed';
        $event->setData($data);
    }

    /**
     * Function: checkSubscription
     *
     * Checks if a customer is already subscribed to the MailChimp list and if so returns the subscriberId.
     * @param $email
     * @return string | bool
     */
    public function checkSubscription($email)
    {
        // Get the list id
        $listId = $this->getConfig('listid');

        $mailChimpClient = new MailchimpClient($this->commerce, $this->getConfig('apikey'));
        $subscriberId = $mailChimpClient->checkSubscription($email, $listId);

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
            $mailChimpClient = new MailchimpClient($this->commerce, $this->getConfig('apikey'));
            $result = $mailChimpClient->subscribeCustomer(
                $this->getConfig('listid'),
                $address,
                $this->getConfig('doubleoptin'),
                $this->getConfig('mailchimp_group'),
            );

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
            $field = new SubscriptionStatus($this->commerce, 'mailchimp_field.subscribe', true);
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
            $client = new MailchimpClient($this->commerce, $apiKey);
            $lists = $client->getLists();

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

            // Group checkbox field
            $listId = $module->getProperty('listid', '');
            $groupValues = $module->getProperty('mailchimp_group');
            if ($categories = $client->getGroupCategories($listId)) {
                $data = [];
                foreach ($categories as $category) {
                    $row = [
                        'id' => $category['id'],
                        'label' => $category['label'],
                        'groups' => [],
                    ];
                    foreach ($client->getGroups($listId, $category['id']) as $group) {
                        $row['groups'][] = [
                            'id' => $group['id'],
                            'label' => $group['label'],
                            'value' => array_key_exists($group['id'], $groupValues) ? '1' : '',
                        ];
                    }
                    $data[] = $row;
                }
                $fields[] = new MailChimpCheckboxGroupField($this->commerce, [
                    'name' => 'properties[mailchimp_group]',
                    'label' => $this->adapter->lexicon('commerce_mailchimp.groups'),
                    'description' => $this->adapter->lexicon('commerce_mailchimp.groups.description'),
                    'value' => $module->getProperty('mailchimp_group', ''),
                    'data' => $data,
                ]);
            }

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
