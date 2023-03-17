<?php

namespace modmore\Commerce_MailChimp\Admin\Widgets\Form;

use modmore\Commerce\Admin\Widgets\Form\TextField;

class CheckboxGroupField extends TextField
{
    public array $data;

    function __construct(\Commerce $commerce, array $options = [])
    {
        if (array_key_exists('data', $options)) {
            $this->data = $options['data'];
        }

        parent::__construct($commerce, $options);
    }

    public function getHTML(): string
    {
        return $this->commerce->view()->render('mailchimp/fields/admin/mailchimpcheckboxgroup.twig', [
            'field' => $this,
            'data' => $this->data,
        ]);
    }

    public function setValue($value): CheckboxGroupField
    {
        return $this;
    }
}
