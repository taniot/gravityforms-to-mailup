# OLD - Gravity Forms To MailUp (Wordpress Plugin)

This is an old version, not compatible with GravityForms 2.5.

Integrate Gravity Forms with MailUp allowing form submissions to be automatically sent to your MailUp account.
This is not an official MailUp plugin.

## Description

The Gravity Forms To MailUp Add-On gives you an easy way to integrate all of your online forms with the MailUp email marketing service. Collect and add subscribers to your email marketing lists automatically when a form is submitted.

## Main features

### Seamless Integration
Automatically add subscribers to your email lists when a form is submitted.

### Custom Fields
Populate MailUp custom fields from form field data.

### Opt-In
Control opt-in and only add subscribers when a certain condition is met.

### Double Opt-In
Automatically send a double opt in message to ensure only legitimate subscribers are added.

## Contribution
There are 2 ways to contribute to this plugin:

1. Report a bug, submit pull request or new feature proposal: visit the [Github repo](https://github.com/taniot/gravityforms-to-mailup).
2. [Buy me a beer! :beer:](//paypal.me/taniot)

## Filters

### Override MailUp Fields
Hook to select whether empty mapped fields should override existing values on MailUp;
defaults to override.

    add_filter('gform_mailup_override_empty_fields', '__return_false' );

### Change Args before submission
Hook to allow args to be changed before sending submission to MailUp

    add_filter( 'gform_mailup_args_pre_subscribe', 'override_mailup_params', 10, 4 );
 
    function override_mailup_params( $params, $form, $entry, $feed ) {
            // do stuff

            return $params;
    }
