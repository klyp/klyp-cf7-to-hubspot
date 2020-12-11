<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

/**
 * Create menu under settings
 * 
 * @return void
 */
function klypCF7ToHubspotMenu()
{
    add_options_page('Klyp CF7 to Hubspot', 'Klyp CF7 to Hubspot', 'manage_options', 'klyp-cf7-to-hubspot', 'klypCF7ToHubspotSettings');
}
add_action( 'admin_menu', 'klypCF7ToHubspotMenu' );

/**
 * Create the settings page
 * 
 * @return void
 */
function klypCF7ToHubspotSettings()
{
    require_once(sprintf("%s/settings-page.php", dirname(__FILE__)));
}

/**
 * Register Plugin settings
 * 
 * @return void
 */
function klypCF7ToHubspotRegisterSettings()
{
    //register our settings
    define('KlypCF7TOHusbspot', 'klyp-cf7-to-hubspot');
    register_setting(KlypCF7TOHusbspot, 'klyp_cf7tohs_api_key');
    register_setting(KlypCF7TOHusbspot, 'klyp_cf7tohs_portal_id');
    register_setting(KlypCF7TOHusbspot, 'klyp_cf7tohs_base_url');
}
add_action( 'admin_init', 'klypCF7ToHubspotRegisterSettings' );

/**
 * Sanitize input
 * 
 * @param string/array
 * @return string/array
 */
function klypCF7ToHubspotSanitizeInput($input)
{
    if (is_array($input)) {
        $return = array ();

        foreach ($input as $key => $value) {
            $return[$key] = sanitize_text_field($value);
        }

        return $return;
    } else {
        return sanitize_text_field($input);
    }
}