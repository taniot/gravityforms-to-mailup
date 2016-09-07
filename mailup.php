<?php
/**
 * Plugin Name: Gravity Forms to MailUp Add-on
 * Plugin URI: https://github.com/taniot/gravityforms-to-mailup
 * Description: Integrate Gravity Forms with MailUp allowing form submissions to be automatically sent to your MailUp account.
 * Version:           0.1.0
 * Author: Gaetano Frascolla
 * Author URI: https://github.com/taniot/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       gravityformsmailup
 * Domain Path:       /languages
 */
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('GF_MAILUP_VERSION', '0.1.0');

add_action('gform_loaded', array('GF_MailUp_Bootstrap', 'load'), 5);

class GF_MailUp_Bootstrap {

    public static function load() {

        if (!method_exists('GFForms', 'include_feed_addon_framework')) {
            return;
        }

        require_once( 'class-gf-mailup.php' );

        GFAddOn::register('GFMailUp');
    }

}

function gf_mailup() {
    return GFMailUp::get_instance();
}
