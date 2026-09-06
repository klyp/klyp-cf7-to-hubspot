<?php
/**
 * One-time data migrations between plugin versions.
 *
 * @package Klyp\CF7ToHubspot
 */

declare(strict_types=1);

namespace Klyp\CF7ToHubspot;

defined('ABSPATH') || exit;

const OPTION_INSTALLED_VERSION = 'klyp_cf7tohs_version';

/**
 * Runs any migration the stored version has not seen yet.
 *
 * @return void
 */
function maybe_upgrade(): void
{
    $installed = (string) get_option(OPTION_INSTALLED_VERSION, '1.0.0');

    if (version_compare($installed, VERSION, '>=')) {
        return;
    }

    if (version_compare($installed, '2.0.0', '<')) {
        upgrade_to_200();
    }

    // Autoloaded: maybe_upgrade() reads it on every admin_init, so a dedicated
    // options query per admin request is worse than riding along with alloptions.
    update_option(OPTION_INSTALLED_VERSION, VERSION, true);
}

/**
 * Migrations for the 2.0.0 rewrite.
 *
 * @return void
 */
function upgrade_to_200(): void
{
    // 1.x accepted any base URL. Re-run it through the hubapi.com allow-list.
    $base_url = (string) get_option(SettingsPage::OPTION_BASE_URL, '');
    $expected = HubSpotClient::normalise_api_base($base_url);

    if ($base_url !== $expected) {
        update_option(SettingsPage::OPTION_BASE_URL, $expected);
    }

    migrate_deal_flags();

    HubSpotClient::flush_form_cache();
}

/**
 * Converts the retired inverted deal flag into an explicit '1' / '0'.
 *
 * 1.x stored only "never create deals" ('true'), so an absent value had to mean
 * "on". Recording the decision explicitly lets the editor render the toggle
 * from storage instead of inferring intent from whether other fields are set.
 *
 * @return void
 */
function migrate_deal_flags(): void
{
    $forms = get_posts(array(
        'post_type'        => 'wpcf7_contact_form',
        'post_status'      => 'any',
        'numberposts'      => -1,
        'fields'           => 'ids',
        'suppress_filters' => false,
    ));

    foreach ($forms as $form_id) {
        $form_id = (int) $form_id;

        if (get_post_meta($form_id, FormSettings::META_CREATE_DEALS, true) !== '') {
            continue;
        }

        $legacy = get_post_meta($form_id, FormSettings::META_DEALBREAKER_ALLOW, true);

        update_post_meta(
            $form_id,
            FormSettings::META_CREATE_DEALS,
            $legacy === 'true' ? '0' : '1'
        );

        delete_post_meta($form_id, FormSettings::META_DEALBREAKER_ALLOW);
    }
}
