<?php
/**
 * Removes plugin options when the plugin is deleted from the Plugins screen.
 *
 * Per-form mappings are left in place: they live on the contact form posts and
 * should survive a reinstall.
 *
 * @package Klyp\CF7ToHubspot
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Option names live on the classes that register them, so this file cannot
// drift from the settings screen the way a second hard-coded list would.
require_once __DIR__ . '/inc/class-hubspot-client.php';
require_once __DIR__ . '/inc/class-form-settings.php';
require_once __DIR__ . '/inc/class-settings-page.php';
require_once __DIR__ . '/inc/class-submission-handler.php';
require_once __DIR__ . '/inc/upgrade.php';

$klyp_cf7hs_options = array(
    \Klyp\CF7ToHubspot\SettingsPage::OPTION_TOKEN,
    \Klyp\CF7ToHubspot\SettingsPage::OPTION_PORTAL_ID,
    \Klyp\CF7ToHubspot\SettingsPage::OPTION_BASE_URL,
    \Klyp\CF7ToHubspot\OPTION_INSTALLED_VERSION,
    // Retired in 2.0.0; still deleted so an upgrade-then-delete leaves nothing.
    'klyp_cf7tohs_api_key',
    'klyp_cf7tohs_cache_generation',
    'klyp_cf7tohs_form_cache_index',
);

foreach ($klyp_cf7hs_options as $klyp_cf7hs_option) {
    delete_option($klyp_cf7hs_option);
}

// Cached responses are transients keyed by cache generation; deleting the
// generation option orphans them and they expire on their own within the TTL.
wp_clear_scheduled_hook(\Klyp\CF7ToHubspot\SubmissionHandler::DEAL_EVENT);

unset($klyp_cf7hs_options, $klyp_cf7hs_option);
