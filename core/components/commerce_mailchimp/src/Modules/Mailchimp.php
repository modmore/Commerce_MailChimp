<?php
namespace modmore\Commerce_MailChimp\Modules;
use modmore\Commerce\Admin\Configuration\About\ComposerPackages;
use modmore\Commerce\Admin\Sections\SimpleSection;
use modmore\Commerce\Admin\Widgets\Form\CheckboxField;
use modmore\Commerce\Admin\Widgets\Form\PasswordField;
use modmore\Commerce\Admin\Widgets\Form\SelectField;
use modmore\Commerce\Events\Admin\PageEvent;
use modmore\Commerce\Events\Checkout;
use modmore\Commerce\Events\OrderState;
use modmore\Commerce\Modules\BaseModule;
use modmore\Commerce\Order\Field\Text;
use modmore\Commerce_MailChimp\Fields\MailChimpSubscriptionField;
use modmore\Commerce_MailChimp\Guzzler\MailChimpGuzzler;
use Symfony\Component\EventDispatcher\EventDispatcher;

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

class Mailchimp extends BaseModule {

    public function getName() {
        $this->adapter->loadLexicon('commerce_mailchimp:default');
        return $this->adapter->lexicon('commerce_mailchimp');
    }

    public function getAuthor() {
        return 'modmore';
    }

    public function getDescription() {
        return $this->adapter->lexicon('commerce_mailchimp.description');
    }

    public function initialize(EventDispatcher $dispatcher) {
        // Load our lexicon
        $this->adapter->loadLexicon('commerce_mailchimp:default');

        // Add composer libraries to the about section (v0.12+)
        $dispatcher->addListener(\Commerce::EVENT_DASHBOARD_LOAD_ABOUT, [$this, 'addLibrariesToAbout']);

        // TODO: Run a check to see if module required settings are complete. Only add the listeners below if so.

        // Check for opt-in value at cart, address and payment steps.
        $dispatcher->addListener(\Commerce::EVENT_CHECKOUT_BEFORE_STEP, [$this, 'checkOptIn']);

        // Checks if an email address is available each step and if the user is already subscribed.
        $dispatcher->addListener(\Commerce::EVENT_CHECKOUT_AFTER_STEP, [$this, 'checkEmailAddress']);

        // Subscribes customer to the designated Mailchimp list.
        $dispatcher->addListener(\Commerce::EVENT_STATE_CART_TO_PROCESSING, [$this, 'subscribeCustomer']);
    }

    /**
     * Function: checkOptIn
     *
     * Checks for a MailChimp opt-in value at three stages during checkout.
     * Adds a flag to the order.
     * @param Checkout $event
     */
    public function checkOptIn(Checkout $event) {
        // Ignore steps that are not cart, address or payment.
        switch($event->getStepKey()) {
            case 'cart':
            case 'address':
            case 'payment':
                $data = $event->getData();
                if($data['mailchimp_opt_in']) {
                    $optIn = filter_var($data['mailchimp_opt_in'],FILTER_SANITIZE_STRING);
                    if($optIn === 'on') {
                        $this->addFieldToOrder($event,true);
                    }
                }
        }
    }

    /**
     * Function: checkEmailAddress
     *
     * Each step of checkout, checks for a logged in user and their email address.
     * @param Checkout $event
     */
    public function checkEmailAddress(Checkout $event) {
        // Check if billing or shipping address should be used.
        $addressType = $this->getConfig('addresstype','billing');
        $order = $event->getOrder();

        $address = $addressType === 'shipping' ? $order->getShippingAddress() : $order->getBillingAddress();
        $isSubscribed = false;
        if ($address instanceof \comOrderAddress) {
            $isSubscribed = $this->checkSubscription($address->get('email'));
        }

        // If customer is already subscribed, add a twig placeholder so the subscribe checkbox can be hidden.
        if($isSubscribed) {
            $data = $event->getData();
            $data['mailchimp_status'] = 'subscribed';
            $event->setData($data);
        }

    }


    /**
     * Function: checkSubscription
     *
     *
     * @param $email
     * @return bool
     */
    public function checkSubscription($email) {
        // If it was determined the customer was subscribed on a previous step, don't repeat the check.
        if($_SESSION['commerce_mailchimp_subscribed']) {
            return true;
        } else {
            // Make sure the email is lowercase.
            $email = strtolower($email);

            // Get an md5 hash of the email address
            $hash = md5($email);

            // Get the list id
            $listId = $this->getConfig('listid');

            $guzzler = new MailChimpGuzzler($this->commerce, $this->getConfig('apikey'));
            $result = $guzzler->checkSubscription($hash, $listId);
            if($result) {
                $_SESSION['commerce_mailchimp_subscribed'] = true;
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Function: addFieldToOrder
     *
     * Adds either a MailChimpSubscriptionField or a Text field to the correct Order depending on whether
     * the customer should be subscribed.
     * @param Checkout $event
     * @param bool $subscribe
     */
    public function addFieldToOrder(Checkout $event,bool $subscribe) {
        $order = $event->getOrder();
        if ($order) {
            if($subscribe) {
                // Add the subscribe order field to the order
                $apiKey = $this->getConfig('apikey');
                $field = new MailChimpSubscriptionField($this->commerce,
                    'mailchimp_order_field.label.subscribe',
                    true
                );
                $field->loadMailChimpData($apiKey);
                $order->setOrderField($field);
            } else {
                // Add a plain text field showing the customer is not subscribed.
                $field = new Text($this->commerce,
                    'mailchimp_order_field.label.not_subscribed',
                    $this->adapter->lexicon('commerce_mailchimp.order_field.value.not_subscribed')
                );
                $order->setOrderField($field);
            }
        } else {
            $this->adapter->log(MODX_LOG_LEVEL_ERROR, 'Unable to retrieve order object.');
        }

    }

    public function subscribeCustomer(OrderState $event) {
        // TODO: Subscribe customer to specified list
        $this->adapter->log(MODX_LOG_LEVEL_ERROR,'Subscribing customer...');


    }

    public function getModuleConfiguration(\comModule $module) {
        $apiKey = $module->getProperty('apikey','');

        $fields = [];
        $fields[] = new PasswordField($this->commerce, [
            'name'          => 'properties[apikey]',
            'label'         => $this->adapter->lexicon('commerce_mailchimp.api_key'),
            'description'   => $this->adapter->lexicon('commerce_mailchimp.api_key.description'),
            'value'         => $apiKey
        ]);

        // On saving the module config modal, the form will reload adding the extra fields once an API key has been added.
        if($apiKey != '') {
            $guzzler = new MailChimpGuzzler($this->commerce, $apiKey);
            $lists = $guzzler->getLists();
            if(!$lists) return false;

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
                        'value' =>  'billing',
                        'label' =>  $this->adapter->lexicon('commerce_mailchimp.address_type.billing')
                    ],
                    [
                        'value' =>  'shipping',
                        'label' =>  $this->adapter->lexicon('commerce_mailchimp.address_type.shipping')
                    ]
                ]
            ]);

            // Checkbox to enable double opt-in for MailChimp subscriptions.
            $fields[] = new CheckboxField($this->commerce, [
                'name'          => 'properties[doubleoptin]',
                'label'         => $this->adapter->lexicon('commerce_mailchimp.double_opt_in'),
                'description'   => $this->adapter->lexicon('commerce_mailchimp.double_opt_in.description'),
                'value'         => $module->getProperty('doubleoptin', '')
            ]);
        }

        return $fields;
    }

    public function addLibrariesToAbout(PageEvent $event) {
        $lockFile = dirname(dirname(__DIR__)) . '/composer.lock';
        if (file_exists($lockFile)) {
            $section = new SimpleSection($this->commerce);
            $section->addWidget(new ComposerPackages($this->commerce, [
                'lockFile' => $lockFile,
                'heading' => $this->adapter->lexicon('commerce.about.open_source_libraries') . ' - ' . $this->adapter->lexicon('commerce_mailchimp'),
                'introduction' => '', // Could add information about how libraries are used, if you'd like
            ]));

            $about = $event->getPage();
            $about->addSection($section);
        }
    }

}
