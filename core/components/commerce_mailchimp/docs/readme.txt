Mailchimp for Commerce
------------------------

Commerce_MailChimp is an official modmore extra for Commerce. During checkout, customers can be signed up to a predefined MailChimp list automatically.

Features
-

- **Opt-In**: Add an opt-in checkbox to your cart, address or payment checkout templates.
- **Double Opt-In**: When enabled, customers will be sent a verification email before they're subscribed to the specified list.
- **List Selection**: Select the MailChimp list to subscribe customers to directly within the Commerce configuration page.
- **Subscription Status**: View a customer's MailChimp subscription status within the Commerce order detail page. If subscribed a link to that subscription within MailChimp is also provided.

Requirements
-

- MODX 2.6.5 or later.
- PHP 7.1 or later.
- MailChimp API Key.
- At least one list set up in the MailChimp account.

Installation
-

Install via the MODX package manager using either the official repo or the Modmore repo.

Usage - Configuration
-

Once the installation has completed, there's some initial configuration that's needed before the module can be used.

From the Commerce dashboard, click the tab `Configuration` then select `Modules` and then `Commerce_MailChimp`.

This will open a pop-up window and a field where you can paste in your MailChimp API key. Click Save.

Providing the API key was valid, you'll be presented with some extra fields.
- **MailChimp List**: This dropdown box will be populated with lists from your MailChimp account. Select one.
- **Address Type**: Here you can select whether the shipping or billing address is used for subscriptions. Select one.
- **Enable Double Opt-In**: (Optional) Enable this if you would like customers to be sent a verification email before they're subscribed to the list.

Click save again!

Usage - Checkout Templates
-

Decide which template you would like to add the opt-in checkbox to:

- `frontend/checkout/cart.twig`
- `frontend/checkout/address.twig`
- `frontend/checkout/payment-method.twig`

In the template of your choice, find the `<form> </form>` tags then add the following between them.

```
{% if mailchimp_enabled and not mailchimp_subscribed %}
    <label class="c-subscribe-newsletter">
        <input type="checkbox" name="mailchimp_opt_in" value="on">
        {{ lex('commerce_mailchimp.subscribe_to_newsletter') }}
    </label>
{% endif %}
```

This will hide the checkbox if the customer is already subscribed but show it if they're not. You can customise the markup as you'd like, so long as the `name` is `mailchimp_opt_in` and the value is `on`.

The `{{ lex('commerce_mailchimp.subscribe_to_newsletter') }}` is a lexicon placeholder that renders the text: __Subscribe to newsletter?__
You can replace this with your own.

If opting-in isn't important on your platform, you can instead add a hidden input between the form tags that will subscribe everyone without asking them.
```
<input type="hidden" name="mailchimp_opt_in" value="on">
```

If the customer opted in to the newsletter, they will be added to the list when the order is paid.
