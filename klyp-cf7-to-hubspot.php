<?php
/**
 * Plugin Name: Klyp Contact Form 7 to HubSpot
 * Plugin URI:  https://github.com/klyp/klyp-cf7-to-hubspot
 * Description: Map Contact Form 7 fields to HubSpot form fields, submit them through the HubSpot Forms API, and optionally create an associated deal.
 * Version:     2.0.0
 * Author:      Klyp
 * Author URI:  https://klyp.co
 * License:     GPL-2.0-only
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: klyp-cf7-to-hubspot
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * Requires Plugins: contact-form-7
 *
 * @package Klyp\CF7ToHubspot
 */

declare(strict_types=1);

namespace Klyp\CF7ToHubspot;

defined('ABSPATH') || exit;

const VERSION     = '2.0.0';
const MIN_PHP     = '8.0';
const MIN_CF7     = '5.8';
const OPTION_GROUP = 'klyp-cf7-to-hubspot';
const MENU_SLUG    = 'klyp-cf7-to-hubspot';

define(__NAMESPACE__ . '\PLUGIN_FILE', __FILE__);
define(__NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path(__FILE__));
define(__NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Renders an admin notice explaining why the plugin refused to boot.
 *
 * The message is built lazily inside the notice callback: this runs from
 * plugins_loaded, and calling __() before init makes WordPress 6.7+ log a
 * "_load_textdomain_just_in_time was called incorrectly" notice.
 *
 * @param callable(): string $message Returns the translated, plain-text reason.
 * @return void
 */
function boot_failure_notice(callable $message): void
{
    add_action('admin_notices', static function () use ($message): void {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
            esc_html__('Klyp CF7 to HubSpot:', 'klyp-cf7-to-hubspot'),
            esc_html($message())
        );
    });
}

/**
 * Verifies the runtime meets the plugin's minimum requirements.
 *
 * @return bool True when the plugin is safe to boot.
 */
function requirements_met(): bool
{
    if (version_compare(PHP_VERSION, MIN_PHP, '<')) {
        boot_failure_notice(static fn (): string => sprintf(
            /* translators: 1: required PHP version, 2: running PHP version */
            __('requires PHP %1$s or newer. This site runs PHP %2$s.', 'klyp-cf7-to-hubspot'),
            MIN_PHP,
            PHP_VERSION
        ));

        return false;
    }

    if (! defined('WPCF7_VERSION')) {
        boot_failure_notice(static fn (): string => __(
            'requires the Contact Form 7 plugin to be installed and activated.',
            'klyp-cf7-to-hubspot'
        ));

        return false;
    }

    if (version_compare(WPCF7_VERSION, MIN_CF7, '<')) {
        boot_failure_notice(static fn (): string => sprintf(
            /* translators: 1: required CF7 version, 2: installed CF7 version */
            __('requires Contact Form 7 %1$s or newer. Version %2$s is installed.', 'klyp-cf7-to-hubspot'),
            MIN_CF7,
            WPCF7_VERSION
        ));

        return false;
    }

    return true;
}

/**
 * Records a diagnostic message.
 *
 * The single logging entry point for the plugin, so the destination and the
 * redaction rules live in one place. Failures that lose data are always logged;
 * routine chatter is gated behind WP_DEBUG.
 *
 * @param string $message Diagnostic message. Never pass a token.
 * @param bool   $debug_only Only log when WP_DEBUG is on.
 * @return void
 */
function log_message(string $message, bool $debug_only = false): void
{
    if ($debug_only && ! (defined('WP_DEBUG') && WP_DEBUG)) {
        return;
    }

    /**
     * Filters a plugin log line, or short-circuits it by returning an empty string.
     *
     * @param string $message The message, without the plugin prefix.
     */
    $message = (string) apply_filters('klyp_cf7tohs_log', $message);

    if ($message === '') {
        return;
    }

    error_log('[klyp-cf7-to-hubspot] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Loads the plugin once all other plugins are known to be present.
 *
 * Contact Form 7 defines WPCF7_VERSION at include time, so the requirement
 * check has to wait until the whole plugin set has been loaded.
 *
 * @return void
 */
function bootstrap(): void
{
    if (! requirements_met()) {
        return;
    }

    require_once PLUGIN_DIR . 'inc/class-hubspot-client.php';
    require_once PLUGIN_DIR . 'inc/class-form-settings.php';
    require_once PLUGIN_DIR . 'inc/class-settings-page.php';
    require_once PLUGIN_DIR . 'inc/class-form-editor.php';
    require_once PLUGIN_DIR . 'inc/class-submission-handler.php';
    require_once PLUGIN_DIR . 'inc/upgrade.php';

    SettingsPage::init();
    FormEditor::init();
    SubmissionHandler::init();

    add_action('admin_init', __NAMESPACE__ . '\maybe_upgrade');
}
add_action('plugins_loaded', __NAMESPACE__ . '\bootstrap');

/**
 * Clears cached HubSpot form definitions on activation and deactivation.
 *
 * @return void
 */
function flush_caches(): void
{
    require_once PLUGIN_DIR . 'inc/class-hubspot-client.php';
    HubSpotClient::flush_form_cache();
}

/**
 * Drops any queued deal work when the plugin is switched off.
 *
 * @return void
 */
function on_deactivate(): void
{
    flush_caches();

    require_once PLUGIN_DIR . 'inc/class-submission-handler.php';
    wp_clear_scheduled_hook(SubmissionHandler::DEAL_EVENT);
}
register_activation_hook(__FILE__, __NAMESPACE__ . '\flush_caches');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\on_deactivate');
