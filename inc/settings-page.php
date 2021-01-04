<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

?>

<div class="wrap">
     
    <h2>Klyp CF7 to Hubspot - Settings</h2>

    <h2 class="nav-tab-wrapper">
        <a href="?page=klyp-cf7-to-hubspot" class="nav-tab nav-tab-active">Settings</a>
    </h2>

    <section>
        <form method="post" action="<?= admin_url('options.php'); ?>">
            <?php
                settings_fields(KlypCF7TOHusbspot);
                do_settings_sections(KlypCF7TOHusbspot);
            ?>
            <div id="klyp-cf7-to-hubspot-api-key-secret" class="klyp-cf7-to-hubspot-api-key">
                <h2 class="title">Hubspot API Key</h2>
                <p>Click <a href="https://knowledge.hubspot.com/integrations/how-do-i-get-my-hubspot-api-key" target="_blank">here</a> on how to access your Hubspot API key</p>
                <input type="text" name="klyp_cf7tohs_api_key" id="klyp_cf7tohs_api_key" class="large-text code" value="<?= esc_attr(get_option('klyp_cf7tohs_api_key')); ?>">
            </div>

            <div id="klyp-cf7-to-hubspot-portal-id" class="klyp-cf7-to-hubspot-portal-id">
                <h2 class="title">Hubspot Portal ID</h2>
                <p>Click <a href="https://knowledge.hubspot.com/account/manage-multiple-hubspot-accounts" target="_blank">here</a> on how to get your portal ID.</p>
                <input type="text" name="klyp_cf7tohs_portal_id" id="klyp_cf7tohs_portal_id" class="large-text code" value="<?= esc_attr(get_option('klyp_cf7tohs_portal_id')); ?>">
            </div>

            <div id="klyp-cf7-to-hubspot-base-url" class="klyp-cf7-to-hubspot-base-url">
                <h2 class="title">Hubspot Base URL</h2>
                <p>Usually <i>https://api.hubapi.com/</i></p>
                <input type="text" name="klyp_cf7tohs_base_url" id="klyp_cf7tohs_base_url" class="large-text code" value="<?= esc_attr(get_option('klyp_cf7tohs_base_url')); ?>">
            </div>

            <?= submit_button('Save Settings'); ?>
        </form>
    </section>
</div>