<?php
namespace modmore\Commerce_MailChimp\Modules;
use modmore\Commerce\Admin\Configuration\About\ComposerPackages;
use modmore\Commerce\Admin\Sections\SimpleSection;
use modmore\Commerce\Admin\Widgets\Form\CheckboxField;
use modmore\Commerce\Admin\Widgets\Form\PasswordField;
use modmore\Commerce\Admin\Widgets\Form\SelectField;
use modmore\Commerce\Events\Admin\PageEvent;
use modmore\Commerce\Modules\BaseModule;
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
            $guzzler = new MailChimpGuzzler($module->adapter, $apiKey);
            $lists = $guzzler->getLists();

            // Select field for MailChimp lists
            $fields[] = new SelectField($this->commerce, [
                'name' => 'properties[listid]',
                'label' => $this->adapter->lexicon('commerce_mailchimp.list'),
                'description' => $this->adapter->lexicon('commerce_mailchimp.list.description'),
                'value' => $module->getProperty('listid', [
                    'value' => null,
                    'label' => $this->adapter->lexicon('commerce_mailchimp.list.select')
                ]),
                'options' => $lists
            ]);

            // Select field for type of address to be submitted (billing or shipping)
            $fields[] = new SelectField($this->commerce, [
                'name' => 'properties[addresstype]',
                'label' => $this->adapter->lexicon('commerce_mailchimp.address_type'),
                'description' => $this->adapter->lexicon('commerce_mailchimp.address_type.description'),
                'value' => $module->getProperty('listid', [
                    'value' => null,
                    'label' => $this->adapter->lexicon('commerce_mailchimp.address_type.select')
                ]),
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
