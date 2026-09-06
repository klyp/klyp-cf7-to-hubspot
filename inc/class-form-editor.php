<?php
/**
 * HubSpot Integration panel inside the Contact Form 7 editor.
 *
 * @package Klyp\CF7ToHubspot
 */

declare(strict_types=1);

namespace Klyp\CF7ToHubspot;

defined('ABSPATH') || exit;

/**
 * Adds and persists the per-form HubSpot configuration.
 *
 * Markup note: Contact Form 7 passes every editor panel through wp_kses() with
 * wpcf7_kses_allowed_html(), so only tags and attributes on that list reach the
 * browser. fieldset, legend, optgroup, hidden, disabled and data-* all survive;
 * <template> does not.
 */
final class FormEditor
{
    private const PANEL_ID = 'klyp-hs-settings-panel';

    /**
     * Settings parsed during wpcf7_save_contact_form, written once the form has a real ID.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $pending = null;

    /**
     * Registers the editor hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_filter('wpcf7_editor_panels', array(self::class, 'register_panel'));
        add_action('wpcf7_save_contact_form', array(self::class, 'stash_settings'), 10, 3);
        add_action('wpcf7_after_save', array(self::class, 'persist_settings'), 10, 1);
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'));
    }

    /**
     * Adds the HubSpot tab to the form editor.
     *
     * @param array<string,array{title:string,callback:callable}> $panels Existing panels.
     * @return array<string,array{title:string,callback:callable}>
     */
    public static function register_panel(array $panels): array
    {
        $panels[self::PANEL_ID] = array(
            'title'    => __('HubSpot Integration', 'klyp-cf7-to-hubspot'),
            'callback' => array(self::class, 'render_panel'),
        );

        return $panels;
    }

    /**
     * Loads the editor stylesheet and script on the CF7 form screen only.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueue_assets(string $hook): void
    {
        // The editor renders on both the form list/edit screen and Add New,
        // whose hook suffixes differ ('toplevel_page_wpcf7' vs
        // '<menu>_page_wpcf7-new'), so match the plugin slug instead.
        if (! str_contains($hook, 'wpcf7')) {
            return;
        }

        wp_enqueue_style(
            'klyp-cf7-to-hubspot-admin',
            PLUGIN_URL . 'assets/css/admin.css',
            array('dashicons'),
            VERSION
        );

        wp_enqueue_script(
            'klyp-cf7-to-hubspot-form-editor',
            PLUGIN_URL . 'assets/js/form-editor.js',
            array(),
            VERSION,
            true
        );

        wp_localize_script('klyp-cf7-to-hubspot-form-editor', 'klypCf7HsEditor', array(
            'i18n' => array(
                'matchedOne'  => __('Added %d mapping. Review it, then save the form.', 'klyp-cf7-to-hubspot'),
                'matchedMany' => __('Added %d mappings. Review them, then save the form.', 'klyp-cf7-to-hubspot'),
                'matchedNone' => __('No unmapped fields share a name with a HubSpot field. Add them by hand.', 'klyp-cf7-to-hubspot'),
            ),
        ));
    }

    /**
     * Renders the panel body.
     *
     * @param \WPCF7_ContactForm $contact_form The form being edited.
     * @return void
     */
    public static function render_panel(\WPCF7_ContactForm $contact_form): void
    {
        $form_id  = (int) $contact_form->id();
        $settings = new FormSettings($form_id);
        $client   = new HubSpotClient();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set after a nonce-checked action.
        $just_flushed = ! empty($_GET['klyp-cf7hs-flushed']);

        ?>
        <div class="klyp-cf7hs-panel">
            <h2><?php esc_html_e('HubSpot Integration', 'klyp-cf7-to-hubspot'); ?></h2>

            <?php if (! $client->is_configured()) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e('HubSpot credentials are not set up yet, so field lists cannot be loaded.', 'klyp-cf7-to-hubspot'); ?>
                        <a href="<?php echo esc_url(SettingsPage::url()); ?>">
                            <?php esc_html_e('Open plugin settings', 'klyp-cf7-to-hubspot'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($just_flushed) : ?>
                <div class="notice notice-success inline is-dismissible">
                    <p><?php esc_html_e('HubSpot field lists refreshed.', 'klyp-cf7-to-hubspot'); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // A rejection that does not name a mapped field lets the mail through
            // rather than costing a lead, so without this it would only ever
            // appear in the PHP error log.
            $last_error = $settings->last_error();

            if ($last_error['message'] !== '') :
                ?>
                <div class="notice notice-error inline">
                    <p>
                        <strong><?php esc_html_e('The last submission was not accepted by HubSpot.', 'klyp-cf7-to-hubspot'); ?></strong>
                        <?php echo esc_html($last_error['message']); ?>
                    </p>
                    <?php if ($last_error['time'] > 0) : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: human-readable time difference, e.g. "5 mins" */
                                esc_html__('%s ago. This clears itself once a submission succeeds.', 'klyp-cf7-to-hubspot'),
                                esc_html(human_time_diff($last_error['time']))
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php
            endif;
            ?>

            <?php
            $hs_fields = $settings->is_enabled() ? $client->get_form_fields($settings->hubspot_form_id()) : array();

            self::render_form_section($settings, $client, $hs_fields, $form_id);

            // Tells the save handler how much of the panel was on screen. Without
            // it, saving the collapsed panel would delete every mapping, because
            // none of those inputs would be present in the request.
            if (! $settings->is_enabled()) {
                echo '<input type="hidden" name="klyp-cf7-to-hubspot-panel" value="minimal">';
                echo '</div>';
                return;
            }

            echo '<input type="hidden" name="klyp-cf7-to-hubspot-panel" value="full">';

            $cf7_fields = self::cf7_field_names($contact_form);

            self::render_field_map($settings, $cf7_fields, $hs_fields);
            self::render_after_submission($settings);
            self::render_deals($settings, $client, $cf7_fields, $hs_fields);
            ?>
        </div>
        <?php
    }

    /**
     * Section 1: which HubSpot form receives submissions, plus load status.
     *
     * @param FormSettings                                        $settings  Current values.
     * @param HubSpotClient                                       $client    Configured client.
     * @param array<int,array{name:string,label:string,type:string}> $hs_fields Loaded HubSpot fields.
     * @param int                                                 $form_id   CF7 post ID.
     * @return void
     */
    private static function render_form_section(
        FormSettings $settings,
        HubSpotClient $client,
        array $hs_fields,
        int $form_id
    ): void {
        $refresh_url = SettingsPage::flush_cache_url(
            admin_url('admin.php?page=wpcf7&post=' . $form_id . '&active-tab=' . self::PANEL_ID)
        );

        ?>
        <fieldset class="klyp-cf7hs-section">
            <legend><?php esc_html_e('1. HubSpot form', 'klyp-cf7-to-hubspot'); ?></legend>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="klyp-cf7-to-hubspot-form-id"><?php esc_html_e('HubSpot form ID', 'klyp-cf7-to-hubspot'); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="klyp-cf7-to-hubspot-form-id"
                            name="klyp-cf7-to-hubspot-form-id"
                            class="code"
                            value="<?php echo esc_attr($settings->hubspot_form_id()); ?>"
                            placeholder="00000000-0000-0000-0000-000000000000"
                            spellcheck="false"
                            aria-describedby="klyp-cf7-to-hubspot-form-id-help"
                        >

                        <?php if ($settings->is_enabled() && $client->is_configured()) : ?>
                            <?php if (array() !== $hs_fields) : ?>
                                <p class="klyp-cf7hs-inline-status klyp-cf7hs-inline-status--ok" role="status">
                                    <span class="klyp-cf7hs-inline-status__dot" aria-hidden="true"></span>
                                    <?php
                                    printf(
                                        /* translators: %s: number of fields */
                                        esc_html(_n('%s HubSpot field loaded and ready to map.', '%s HubSpot fields loaded and ready to map.', count($hs_fields), 'klyp-cf7-to-hubspot')),
                                        esc_html(number_format_i18n(count($hs_fields)))
                                    );
                                    ?>
                                    <a href="<?php echo esc_url($refresh_url); ?>"><?php esc_html_e('Refresh', 'klyp-cf7-to-hubspot'); ?></a>
                                </p>
                            <?php else : ?>
                                <p class="klyp-cf7hs-inline-status klyp-cf7hs-inline-status--error" role="status">
                                    <span class="klyp-cf7hs-inline-status__dot" aria-hidden="true"></span>
                                    <?php esc_html_e('No fields came back for this ID. Check it against the list on the plugin settings screen, and that the token has the "forms" scope.', 'klyp-cf7-to-hubspot'); ?>
                                    <a href="<?php echo esc_url($refresh_url); ?>"><?php esc_html_e('Try again', 'klyp-cf7-to-hubspot'); ?></a>
                                    <a href="<?php echo esc_url(SettingsPage::url()); ?>"><?php esc_html_e('Open settings', 'klyp-cf7-to-hubspot'); ?></a>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <p id="klyp-cf7-to-hubspot-form-id-help" class="description">
                            <?php if ($settings->is_enabled()) : ?>
                                <?php esc_html_e('Every HubSpot form ID is listed under "Your HubSpot forms" on the plugin settings screen, with a copy button.', 'klyp-cf7-to-hubspot'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Paste the GUID of the HubSpot form that should receive submissions, then save this form. The mapping options appear once the ID is saved.', 'klyp-cf7-to-hubspot'); ?>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(SettingsPage::url()); ?>"><?php esc_html_e('Find a form ID', 'klyp-cf7-to-hubspot'); ?></a>
                        </p>
                    </td>
                </tr>
            </table>
        </fieldset>
        <?php
    }

    /**
     * Section 2: the CF7-to-HubSpot field mapping repeater.
     *
     * @param FormSettings                                        $settings   Current values.
     * @param array<int,string>                                   $cf7_fields CF7 field names.
     * @param array<int,array{name:string,label:string,type:string}> $hs_fields HubSpot fields.
     * @return void
     */
    private static function render_field_map(
        FormSettings $settings,
        array $cf7_fields,
        array $hs_fields
    ): void {
        $rows        = $settings->field_map();
        $cf7_options = self::cf7_options($cf7_fields);
        $hs_options  = self::hs_options($hs_fields);
        $cf7_blank   = __('— Contact Form 7 field —', 'klyp-cf7-to-hubspot');
        $hs_blank    = __('— HubSpot field —', 'klyp-cf7-to-hubspot');

        ?>
        <fieldset class="klyp-cf7hs-section">
            <legend><?php esc_html_e('2. Field mapping', 'klyp-cf7-to-hubspot'); ?></legend>
            <p class="description">
                <?php esc_html_e('Each row sends one Contact Form 7 field into one HubSpot field. Fields that are not mapped are not sent.', 'klyp-cf7-to-hubspot'); ?>
            </p>

            <div class="klyp-cf7hs-repeater" data-repeater="map">
                <table class="widefat striped klyp-cf7hs-repeater__table">
                    <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Contact Form 7 field', 'klyp-cf7-to-hubspot'); ?></th>
                        <th scope="col" class="klyp-cf7hs-repeater__arrow" aria-hidden="true"></th>
                        <th scope="col"><?php esc_html_e('HubSpot field', 'klyp-cf7-to-hubspot'); ?></th>
                        <th scope="col" class="klyp-cf7hs-repeater__actions">
                            <span class="screen-reader-text"><?php esc_html_e('Actions', 'klyp-cf7-to-hubspot'); ?></span>
                        </th>
                    </tr>
                    </thead>
                    <tbody data-repeater-rows>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php self::render_select('', 'klyp-cf7-to-hubspot-cf-map-fields[]', $cf7_options, $row['cf7'], $cf7_blank); ?></td>
                            <td class="klyp-cf7hs-repeater__arrow" aria-hidden="true">→</td>
                            <td><?php self::render_select('', 'klyp-cf7-to-hubspot-hs-map-fields[]', $hs_options, $row['hubspot'], $hs_blank); ?></td>
                            <td class="klyp-cf7hs-repeater__actions"><?php self::render_remove_button(); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tbody data-repeater-empty <?php echo array() !== $rows ? 'hidden' : ''; ?>>
                    <tr>
                        <td colspan="4" class="klyp-cf7hs-repeater__empty">
                            <?php esc_html_e('Nothing is mapped yet, so submissions would reach HubSpot empty. Add a row, or match fields by name.', 'klyp-cf7-to-hubspot'); ?>
                        </td>
                    </tr>
                    </tbody>
                    <?php
                    // Row template. Every control is disabled so the browser never
                    // submits it — 1.x used a display:none <tfoot> whose inputs DID
                    // submit, appending a blank row to every mapping on each save.
                    ?>
                    <tbody data-repeater-template class="klyp-cf7hs-repeater__template" hidden>
                    <tr>
                        <td><?php self::render_select('', 'klyp-cf7-to-hubspot-cf-map-fields[]', $cf7_options, '', $cf7_blank, true); ?></td>
                        <td class="klyp-cf7hs-repeater__arrow" aria-hidden="true">→</td>
                        <td><?php self::render_select('', 'klyp-cf7-to-hubspot-hs-map-fields[]', $hs_options, '', $hs_blank, true); ?></td>
                        <td class="klyp-cf7hs-repeater__actions"><?php self::render_remove_button(true); ?></td>
                    </tr>
                    </tbody>
                </table>

                <p class="klyp-cf7hs-repeater__toolbar">
                    <button type="button" class="button button-secondary" data-repeater-add>
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <?php esc_html_e('Add field mapping', 'klyp-cf7-to-hubspot'); ?>
                    </button>
                    <button type="button" class="button" data-repeater-match>
                        <?php esc_html_e('Match fields by name', 'klyp-cf7-to-hubspot'); ?>
                    </button>
                    <span class="klyp-cf7hs-repeater__result" data-repeater-match-result role="status" aria-live="polite"></span>
                </p>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Section 3: what happens to the visitor after a successful send.
     *
     * @param FormSettings $settings Current values.
     * @return void
     */
    private static function render_after_submission(FormSettings $settings): void
    {
        ?>
        <fieldset class="klyp-cf7hs-section">
            <legend><?php esc_html_e('3. After submission', 'klyp-cf7-to-hubspot'); ?></legend>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="klyp-cf7-to-hubspot-form-redirect"><?php esc_html_e('Redirect to', 'klyp-cf7-to-hubspot'); ?></label>
                    </th>
                    <td>
                        <?php
                        // Deliberately type="text", not type="url". Contact Form 7's
                        // editor form has no novalidate, so a type="url" control
                        // holding a root-relative path like /thank-you/ fails browser
                        // constraint validation and blocks Save on every tab — and
                        // the control is inside a hidden panel, so the browser cannot
                        // even focus it to explain why.
                        ?>
                        <input
                            type="text"
                            id="klyp-cf7-to-hubspot-form-redirect"
                            name="klyp-cf7-to-hubspot-form-redirect"
                            class="code"
                            value="<?php echo esc_attr($settings->redirect_raw()); ?>"
                            placeholder="<?php echo esc_attr(home_url('/thank-you/')); ?>"
                            spellcheck="false"
                            aria-describedby="klyp-cf7-to-hubspot-form-redirect-help"
                        >
                        <?php self::render_redirect_warning($settings); ?>
                        <p id="klyp-cf7-to-hubspot-form-redirect-help" class="description">
                            <?php esc_html_e('Optional. Leave empty to show the usual Contact Form 7 success message instead. A full URL or a path such as /thank-you/ — only targets on this site are honoured.', 'klyp-cf7-to-hubspot'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </fieldset>
        <?php
    }

    /**
     * Warns when a stored redirect will be ignored at runtime.
     *
     * redirect_url() drops anything wp_validate_redirect() rejects. Without this
     * the editor would keep displaying an off-site URL that silently never fires.
     *
     * @param FormSettings $settings Current values.
     * @return void
     */
    private static function render_redirect_warning(FormSettings $settings): void
    {
        $raw = $settings->redirect_raw();

        if ($raw === '' || $settings->redirect_url() !== '') {
            return;
        }

        ?>
        <p class="klyp-cf7hs-inline-status klyp-cf7hs-inline-status--error" role="status">
            <span class="klyp-cf7hs-inline-status__dot" aria-hidden="true"></span>
            <?php esc_html_e('This redirect points off this site, so it will be ignored and visitors will see the success message instead. Use a URL on this site, or add the host with the allowed_redirect_hosts filter.', 'klyp-cf7-to-hubspot'); ?>
        </p>
        <?php
    }

    /**
     * Section 4: deal creation, behind a positive toggle.
     *
     * @param FormSettings                                        $settings   Current values.
     * @param HubSpotClient                                       $client     Configured client.
     * @param array<int,string>                                   $cf7_fields CF7 field names.
     * @param array<int,array{name:string,label:string,type:string}> $hs_fields HubSpot fields.
     * @return void
     */
    private static function render_deals(
        FormSettings $settings,
        HubSpotClient $client,
        array $cf7_fields,
        array $hs_fields
    ): void {
        // Straight from storage: the explicit '1'/'0' flag means the checkbox
        // reflects what was saved, so ticking it and saving before filling in the
        // email fields no longer silently reverts.
        $enabled = $settings->deals_enabled();

        // Only pay for the pipelines request when the section is actually usable.
        $pipelines = ($enabled && $client->is_configured())
            ? $client->get_deal_pipelines()
            : array('pipelines' => array(), 'error' => '');
        $has_lists = array() !== $pipelines['pipelines'];

        ?>
        <fieldset class="klyp-cf7hs-section">
            <legend><?php esc_html_e('4. Deals', 'klyp-cf7-to-hubspot'); ?></legend>

            <p class="klyp-cf7hs-toggle">
                <label for="klyp-cf7-to-hubspot-create-deals">
                    <input
                        type="checkbox"
                        id="klyp-cf7-to-hubspot-create-deals"
                        name="klyp-cf7-to-hubspot-create-deals"
                        value="1"
                        data-shows="klyp-cf7-to-hubspot-deal-options"
                        <?php checked($enabled); ?>
                    >
                    <strong><?php esc_html_e('Create a HubSpot deal for each submission', 'klyp-cf7-to-hubspot'); ?></strong>
                </label>
                <span class="description">
                    <?php esc_html_e('The visitor is matched to a HubSpot contact by email address and the deal is attached to that contact.', 'klyp-cf7-to-hubspot'); ?>
                </span>
            </p>

            <div id="klyp-cf7-to-hubspot-deal-options" class="klyp-cf7hs-deal-options" <?php echo $enabled ? '' : 'hidden'; ?>>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="klyp-cf7-to-hubspot-cf7-email-field"><?php esc_html_e('Visitor email field', 'klyp-cf7-to-hubspot'); ?></label>
                        </th>
                        <td>
                            <?php
                            self::render_select(
                                'klyp-cf7-to-hubspot-cf7-email-field',
                                'klyp-cf7-to-hubspot-cf7-email-field',
                                self::cf7_options($cf7_fields),
                                $settings->cf7_email_field(),
                                __('— Contact Form 7 field —', 'klyp-cf7-to-hubspot')
                            );
                            ?>
                            <p class="description"><?php esc_html_e('The Contact Form 7 field that holds the email address.', 'klyp-cf7-to-hubspot'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="klyp-cf7-to-hubspot-email-field"><?php esc_html_e('HubSpot email field', 'klyp-cf7-to-hubspot'); ?></label>
                        </th>
                        <td>
                            <?php
                            self::render_select(
                                'klyp-cf7-to-hubspot-email-field',
                                'klyp-cf7-to-hubspot-email-field',
                                self::hs_options($hs_fields),
                                $settings->hubspot_email_field(),
                                __('— HubSpot field —', 'klyp-cf7-to-hubspot')
                            );
                            ?>
                            <p class="description"><?php esc_html_e('Usually "Email (email)".', 'klyp-cf7-to-hubspot'); ?></p>
                        </td>
                    </tr>

                    <?php if ($has_lists) : ?>
                        <tr>
                            <th scope="row">
                                <label for="klyp-cf7-to-hubspot-pipeline-id"><?php esc_html_e('Pipeline', 'klyp-cf7-to-hubspot'); ?></label>
                            </th>
                            <td>
                                <?php
                                $pipeline_options = array();
                                $stage_groups     = array();

                                foreach ($pipelines['pipelines'] as $pipeline) {
                                    $pipeline_options[$pipeline['id']] = $pipeline['label'];
                                    $stage_groups[$pipeline['id']]     = array(
                                        'label'   => $pipeline['label'],
                                        'options' => array_column($pipeline['stages'], 'label', 'id'),
                                    );
                                }

                                self::render_select(
                                    'klyp-cf7-to-hubspot-pipeline-id',
                                    'klyp-cf7-to-hubspot-pipeline-id',
                                    $pipeline_options,
                                    $settings->pipeline_id(),
                                    __('— Select a pipeline —', 'klyp-cf7-to-hubspot')
                                );
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="klyp-cf7-to-hubspot-stage-id"><?php esc_html_e('Deal stage', 'klyp-cf7-to-hubspot'); ?></label>
                            </th>
                            <td>
                                <?php
                                self::render_grouped_select(
                                    'klyp-cf7-to-hubspot-stage-id',
                                    'klyp-cf7-to-hubspot-stage-id',
                                    $stage_groups,
                                    $settings->stage_id(),
                                    __('— Select a stage —', 'klyp-cf7-to-hubspot'),
                                    array('data-stage-for' => 'klyp-cf7-to-hubspot-pipeline-id')
                                );
                                ?>
                                <p class="description"><?php esc_html_e('Stages are grouped by pipeline; choosing a pipeline above narrows this list.', 'klyp-cf7-to-hubspot'); ?></p>
                            </td>
                        </tr>
                    <?php else : ?>
                        <tr>
                            <th scope="row">
                                <label for="klyp-cf7-to-hubspot-pipeline-id"><?php esc_html_e('Pipeline ID', 'klyp-cf7-to-hubspot'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="klyp-cf7-to-hubspot-pipeline-id" name="klyp-cf7-to-hubspot-pipeline-id" class="code" value="<?php echo esc_attr($settings->pipeline_id()); ?>" placeholder="default">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="klyp-cf7-to-hubspot-stage-id"><?php esc_html_e('Deal stage ID', 'klyp-cf7-to-hubspot'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="klyp-cf7-to-hubspot-stage-id" name="klyp-cf7-to-hubspot-stage-id" class="code" value="<?php echo esc_attr($settings->stage_id()); ?>" placeholder="appointmentscheduled">
                                <p class="description">
                                    <?php if ($client->is_configured()) : ?>
                                        <?php esc_html_e('Grant the private app the crm.objects.deals.read scope and these become drop-downs of your pipelines and stages.', 'klyp-cf7-to-hubspot'); ?>
                                    <?php else : ?>
                                        <?php esc_html_e('Connect HubSpot on the plugin settings screen and these become drop-downs of your pipelines and stages.', 'klyp-cf7-to-hubspot'); ?>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php self::render_dealbreakers($settings, $cf7_fields); ?>
            </div>
        </fieldset>
        <?php
    }

    /**
     * The "skip the deal when…" repeater, inside the deal options.
     *
     * @param FormSettings      $settings   Current values.
     * @param array<int,string> $cf7_fields CF7 field names.
     * @return void
     */
    private static function render_dealbreakers(FormSettings $settings, array $cf7_fields): void
    {
        $rows        = $settings->dealbreakers();
        $cf7_options = self::cf7_options($cf7_fields);
        $blank       = __('— Contact Form 7 field —', 'klyp-cf7-to-hubspot');

        ?>
        <h4 class="klyp-cf7hs-subheading"><?php esc_html_e('Skip the deal when…', 'klyp-cf7-to-hubspot'); ?></h4>
        <p class="description">
            <?php esc_html_e('Optional. If any condition matches, the submission still reaches HubSpot but no deal is created — handy for "existing customer" or "job application" enquiries.', 'klyp-cf7-to-hubspot'); ?>
        </p>

        <div class="klyp-cf7hs-repeater" data-repeater="dealbreaker">
            <table class="widefat striped klyp-cf7hs-repeater__table">
                <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('This field', 'klyp-cf7-to-hubspot'); ?></th>
                    <th scope="col" class="klyp-cf7hs-repeater__arrow" aria-hidden="true"></th>
                    <th scope="col"><?php esc_html_e('exactly equals', 'klyp-cf7-to-hubspot'); ?></th>
                    <th scope="col" class="klyp-cf7hs-repeater__actions">
                        <span class="screen-reader-text"><?php esc_html_e('Actions', 'klyp-cf7-to-hubspot'); ?></span>
                    </th>
                </tr>
                </thead>
                <tbody data-repeater-rows>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php self::render_select('', 'klyp-cf7-to-hubspot-dealbreaker-field[]', $cf7_options, $row['field'], $blank); ?></td>
                        <td class="klyp-cf7hs-repeater__arrow" aria-hidden="true">=</td>
                        <td><?php self::render_value_input($row['value']); ?></td>
                        <td class="klyp-cf7hs-repeater__actions"><?php self::render_remove_button(); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tbody data-repeater-empty <?php echo array() !== $rows ? 'hidden' : ''; ?>>
                <tr>
                    <td colspan="4" class="klyp-cf7hs-repeater__empty">
                        <?php esc_html_e('No conditions — a deal is created for every submission.', 'klyp-cf7-to-hubspot'); ?>
                    </td>
                </tr>
                </tbody>
                <tbody data-repeater-template class="klyp-cf7hs-repeater__template" hidden>
                <tr>
                    <td><?php self::render_select('', 'klyp-cf7-to-hubspot-dealbreaker-field[]', $cf7_options, '', $blank, true); ?></td>
                    <td class="klyp-cf7hs-repeater__arrow" aria-hidden="true">=</td>
                    <td><?php self::render_value_input('', true); ?></td>
                    <td class="klyp-cf7hs-repeater__actions"><?php self::render_remove_button(true); ?></td>
                </tr>
                </tbody>
            </table>

            <p class="klyp-cf7hs-repeater__toolbar">
                <button type="button" class="button button-secondary" data-repeater-add>
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e('Add condition', 'klyp-cf7-to-hubspot'); ?>
                </button>
            </p>
        </div>
        <?php
    }

    /**
     * Outputs the free-text value input used by exclusion rows.
     *
     * @param string $value    Current value.
     * @param bool   $disabled Render inert, for the hidden row template.
     * @return void
     */
    private static function render_value_input(string $value, bool $disabled = false): void
    {
        ?>
        <input
            type="text"
            name="klyp-cf7-to-hubspot-dealbreaker-value[]"
            class="code"
            value="<?php echo esc_attr($value); ?>"
            placeholder="<?php esc_attr_e('e.g. Existing customer', 'klyp-cf7-to-hubspot'); ?>"
            aria-label="<?php esc_attr_e('Value that skips deal creation', 'klyp-cf7-to-hubspot'); ?>"
            <?php disabled($disabled); ?>
        >
        <?php
    }

    /**
     * Outputs a remove-row button.
     *
     * @param bool $disabled Render inert, for use inside the hidden row template.
     * @return void
     */
    private static function render_remove_button(bool $disabled = false): void
    {
        ?>
        <button type="button" class="button-link klyp-cf7hs-repeater__remove" data-repeater-remove <?php disabled($disabled); ?>>
            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Remove this row', 'klyp-cf7-to-hubspot'); ?></span>
        </button>
        <?php
    }

    /**
     * Outputs an escaped `<select>`.
     *
     * @param string               $id       Element ID, or an empty string for repeater rows.
     * @param string               $name     Element name.
     * @param array<string,string> $options  Value => label.
     * @param string               $selected Currently selected value.
     * @param string               $blank    Label for the empty option.
     * @param bool                 $disabled Render disabled so the browser never submits it.
     * @param array<string,string> $attrs    Extra attributes (already-safe names; values escaped here).
     * @return void
     */
    private static function render_select(
        string $id,
        string $name,
        array $options,
        string $selected,
        string $blank,
        bool $disabled = false,
        array $attrs = array()
    ): void {
        self::open_select($id, $name, $disabled, $attrs);

        printf('<option value="">%s</option>', esc_html($blank));

        foreach ($options as $value => $label) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr((string) $value),
                selected($selected, (string) $value, false),
                esc_html($label)
            );
        }

        self::close_select($selected, $selected === '' || array_key_exists($selected, $options));
    }

    /**
     * Outputs an escaped `<select>` whose options are grouped with `<optgroup>`.
     *
     * @param string                                                     $id       Element ID.
     * @param string                                                     $name     Element name.
     * @param array<string,array{label:string,options:array<string,string>}> $groups Group key => label + options.
     * @param string                                                     $selected Currently selected value.
     * @param string                                                     $blank    Label for the empty option.
     * @param array<string,string>                                       $attrs    Extra attributes.
     * @return void
     */
    private static function render_grouped_select(
        string $id,
        string $name,
        array $groups,
        string $selected,
        string $blank,
        array $attrs = array()
    ): void {
        self::open_select($id, $name, false, $attrs);

        printf('<option value="">%s</option>', esc_html($blank));

        $found = $selected === '';

        foreach ($groups as $group_key => $group) {
            printf(
                '<optgroup label="%1$s" data-group="%2$s">',
                esc_attr($group['label']),
                esc_attr((string) $group_key)
            );

            foreach ($group['options'] as $value => $label) {
                if ((string) $value === $selected) {
                    $found = true;
                }

                printf(
                    '<option value="%1$s"%2$s>%3$s</option>',
                    esc_attr((string) $value),
                    selected($selected, (string) $value, false),
                    esc_html($label)
                );
            }

            echo '</optgroup>';
        }

        self::close_select($selected, $found);
    }

    /**
     * @param string               $id       Element ID or empty.
     * @param string               $name     Element name.
     * @param bool                 $disabled Whether to render disabled.
     * @param array<string,string> $attrs    Extra attributes.
     * @return void
     */
    private static function open_select(string $id, string $name, bool $disabled, array $attrs): void
    {
        $extra = '';

        foreach ($attrs as $attr => $value) {
            $attr = preg_replace('/[^a-z0-9\-]/', '', strtolower($attr)) ?? '';

            if ($attr === '') {
                continue;
            }

            $extra .= $value === ''
                ? ' ' . $attr
                : sprintf(' %s="%s"', $attr, esc_attr($value));
        }

        printf(
            '<select%1$s name="%2$s" class="klyp-cf7hs-select"%3$s%4$s>',
            $id !== '' ? ' id="' . esc_attr($id) . '"' : '',
            esc_attr($name),
            $disabled ? ' disabled' : '',
            $extra // Built from escaped parts above.
        );
    }

    /**
     * Closes a select, first preserving a stored value that no longer exists upstream.
     *
     * @param string $selected Stored value.
     * @param bool   $found    Whether the stored value was among the options.
     * @return void
     */
    private static function close_select(string $selected, bool $found): void
    {
        if (! $found) {
            printf(
                '<option value="%1$s" selected>%2$s</option>',
                esc_attr($selected),
                esc_html(sprintf(
                    /* translators: %s: stored field name that no longer exists */
                    __('%s (no longer available)', 'klyp-cf7-to-hubspot'),
                    $selected
                ))
            );
        }

        echo '</select>';
    }

    /**
     * @param array<int,string> $cf7_fields Field names.
     * @return array<string,string> Value => label.
     */
    private static function cf7_options(array $cf7_fields): array
    {
        $options = array();

        foreach ($cf7_fields as $name) {
            $options[$name] = $name;
        }

        return $options;
    }

    /**
     * @param array<int,array{name:string,label:string,type:string}> $hs_fields HubSpot fields.
     * @return array<string,string> Value => label.
     */
    private static function hs_options(array $hs_fields): array
    {
        $options = array();

        foreach ($hs_fields as $field) {
            $options[$field['name']] = sprintf('%s (%s)', $field['label'], $field['name']);
        }

        return $options;
    }

    /**
     * Lists the named form-tags on a CF7 form.
     *
     * @param \WPCF7_ContactForm $contact_form Form being edited.
     * @return array<int,string> Unique field names.
     */
    private static function cf7_field_names(\WPCF7_ContactForm $contact_form): array
    {
        if (method_exists($contact_form, 'collect_mail_tags')) {
            // Excludes the tag types Contact Form 7 never posts (submit, response,
            // count and friends), so the pickers cannot offer a field that would
            // silently send nothing.
            return array_values((array) $contact_form->collect_mail_tags());
        }

        $names = array();

        foreach ($contact_form->scan_form_tags() as $tag) {
            $name = isset($tag->name) ? (string) $tag->name : '';

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Captures the submitted HubSpot settings before Contact Form 7 saves the form.
     *
     * @param \WPCF7_ContactForm  $contact_form The form.
     * @param array<string,mixed> $args         Unslashed request data.
     * @param string|null         $context      Save context. Contact Form 7's REST
     *                                          routes pass the raw `context` param,
     *                                          which is null when omitted — a typed
     *                                          `string` here fatals the whole route.
     * @return void
     */
    public static function stash_settings(
        \WPCF7_ContactForm $contact_form,
        array $args = array(),
        ?string $context = null
    ): void {
        unset($contact_form);

        if ($context !== 'save') {
            return;
        }

        // Only act on a submit that actually carried our panel.
        if (! array_key_exists('klyp-cf7-to-hubspot-form-id', $args)) {
            return;
        }

        self::$pending = FormSettings::sanitize_submitted($args);
    }

    /**
     * Writes the captured settings once the form has its final post ID.
     *
     * A new form is still ID -1 during wpcf7_save_contact_form, which is why 1.x
     * silently wrote the mapping to post -1 the first time a form was saved.
     *
     * @param \WPCF7_ContactForm $contact_form The saved form.
     * @return void
     */
    public static function persist_settings(\WPCF7_ContactForm $contact_form): void
    {
        if (null === self::$pending) {
            return;
        }

        $values        = self::$pending;
        self::$pending = null;

        $form_id = (int) $contact_form->id();

        if ($form_id <= 0) {
            return;
        }

        FormSettings::save($form_id, $values);
    }
}
