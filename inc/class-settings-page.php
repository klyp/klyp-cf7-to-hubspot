<?php
/**
 * Plugin settings screen.
 *
 * @package Klyp\CF7ToHubspot
 */

declare(strict_types=1);

namespace Klyp\CF7ToHubspot;

defined('ABSPATH') || exit;

/**
 * Renders and handles Settings -> Klyp CF7 to HubSpot.
 */
final class SettingsPage
{
    public const OPTION_TOKEN     = 'klyp_cf7tohs_api_key_private';
    public const OPTION_PORTAL_ID = 'klyp_cf7tohs_portal_id';
    public const OPTION_BASE_URL  = 'klyp_cf7tohs_base_url';

    /** Retired option from 1.x, kept only so we can warn about and clear it. */
    private const OPTION_LEGACY_KEY = 'klyp_cf7tohs_api_key';

    private const CAPABILITY = 'manage_options';

    private const AJAX_ACTION = 'klyp_cf7tohs_test_connection';

    private const FLUSH_ACTION = 'klyp_cf7tohs_flush_cache';

    private const DROP_LEGACY_ACTION = 'klyp_cf7tohs_drop_legacy_key';

    /**
     * Registers the hooks that build the screen.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', array(self::class, 'register_menu'));
        add_action('admin_init', array(self::class, 'register_settings'));
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array(self::class, 'handle_test_connection'));
        add_action('admin_post_' . self::FLUSH_ACTION, array(self::class, 'handle_flush_cache'));
        add_action('admin_post_' . self::DROP_LEGACY_ACTION, array(self::class, 'handle_drop_legacy_key'));

        // Cache keys incorporate the token, so a new token must not read entries
        // fetched with the old one.
        add_action('add_option_' . self::OPTION_TOKEN, array(HubSpotClient::class, 'flush_form_cache'));
        add_action('update_option_' . self::OPTION_TOKEN, array(HubSpotClient::class, 'flush_form_cache'));

        add_filter(
            'plugin_action_links_' . plugin_basename(PLUGIN_FILE),
            array(self::class, 'add_settings_link')
        );
    }

    /**
     * Adds a Settings shortcut to the plugin row.
     *
     * @param array<int,string> $links Existing action links.
     * @return array<int,string>
     */
    public static function add_settings_link(array $links): array
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::url()),
            esc_html__('Settings', 'klyp-cf7-to-hubspot')
        ));

        return $links;
    }

    /**
     * @param array<string,string> $args Extra query arguments.
     * @return string Admin URL for this settings screen.
     */
    public static function url(array $args = array()): string
    {
        return add_query_arg(
            array_merge(array('page' => MENU_SLUG), $args),
            admin_url('options-general.php')
        );
    }

    /**
     * Adds the options-page entry.
     *
     * @return void
     */
    public static function register_menu(): void
    {
        add_options_page(
            __('Klyp CF7 to HubSpot', 'klyp-cf7-to-hubspot'),
            __('CF7 to HubSpot', 'klyp-cf7-to-hubspot'),
            self::CAPABILITY,
            MENU_SLUG,
            array(self::class, 'render')
        );
    }

    /**
     * Registers the three stored options with explicit sanitisers.
     *
     * @return void
     */
    public static function register_settings(): void
    {
        register_setting(OPTION_GROUP, self::OPTION_TOKEN, array(
            'type'              => 'string',
            'sanitize_callback' => array(self::class, 'sanitize_token'),
            'default'           => '',
            'show_in_rest'      => false,
        ));

        register_setting(OPTION_GROUP, self::OPTION_PORTAL_ID, array(
            'type'              => 'string',
            'sanitize_callback' => array(self::class, 'sanitize_portal_id'),
            'default'           => '',
            'show_in_rest'      => false,
        ));

        register_setting(OPTION_GROUP, self::OPTION_BASE_URL, array(
            'type'              => 'string',
            'sanitize_callback' => array(self::class, 'sanitize_base_url'),
            'default'           => HubSpotClient::DEFAULT_API_BASE,
            'show_in_rest'      => false,
        ));
    }

    /**
     * Keeps the stored token when the field is submitted blank.
     *
     * The field is rendered empty so the secret is never written into the page
     * source, which means "blank" has to mean "unchanged" rather than "clear".
     *
     * @param mixed $value Raw submitted value.
     * @return string
     */
    public static function sanitize_token(mixed $value): string
    {
        $existing = (string) get_option(self::OPTION_TOKEN, '');
        $value    = is_scalar($value) ? trim((string) $value) : '';

        // phpcs:disable WordPress.Security.NonceVerification -- Options API verifies the nonce before sanitisation runs.
        $from_settings_form = isset($_POST['option_page'])
            && wp_unslash($_POST['option_page']) === OPTION_GROUP;
        $clear_requested    = $from_settings_form && ! empty($_POST['klyp_cf7tohs_clear_token']);
        // phpcs:enable WordPress.Security.NonceVerification

        // An explicit checkbox is the only way to erase a saved token from the
        // form. Gated on the settings form so an unrelated update_option() in a
        // request that happens to carry that POST key cannot blank the token.
        if ($clear_requested) {
            return '';
        }

        // "Blank means unchanged" only applies to the settings screen, where the
        // field is deliberately rendered empty. A programmatic or WP-CLI
        // update_option($option, '') must still be able to clear the token.
        if ($value === '' && $from_settings_form) {
            return $existing;
        }

        if ($value === '') {
            return '';
        }

        $value = sanitize_text_field($value);

        if (! str_starts_with($value, 'pat-')) {
            add_settings_error(
                OPTION_GROUP,
                'token-format',
                __('That does not look like a HubSpot private app token (they start with "pat-"). It has been saved anyway — the connection test below will confirm whether it works.', 'klyp-cf7-to-hubspot'),
                'warning'
            );
        }

        return $value;
    }

    /**
     * @param mixed $value Raw submitted value.
     * @return string Digits only.
     */
    public static function sanitize_portal_id(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        $clean = preg_replace('/\D+/', '', $value) ?? '';

        if ($value !== '' && $clean === '') {
            add_settings_error(
                OPTION_GROUP,
                'portal-id',
                __('The portal ID must be numeric. Your entry was discarded.', 'klyp-cf7-to-hubspot'),
                'error'
            );
        }

        return $clean;
    }

    /**
     * @param mixed $value Raw submitted value.
     * @return string A HubSpot host, or the default.
     */
    public static function sanitize_base_url(mixed $value): string
    {
        $value      = is_scalar($value) ? trim((string) $value) : '';
        $normalised = HubSpotClient::normalise_api_base($value);

        // Compare hosts, not strings. normalise_api_base() also lowercases and
        // adds the scheme, so "api.hubapi.com" and "HTTPS://API.HUBAPI.COM/" are
        // accepted rather than being reported as rejected.
        $submitted_host = strtolower((string) wp_parse_url(
            str_contains($value, '//') ? $value : 'https://' . $value,
            PHP_URL_HOST
        ));
        $accepted_host  = strtolower((string) wp_parse_url($normalised, PHP_URL_HOST));

        if ($value !== '' && $submitted_host !== $accepted_host) {
            add_settings_error(
                OPTION_GROUP,
                'base-url',
                sprintf(
                    /* translators: %s: default HubSpot API URL */
                    __('The API base URL must point at hubapi.com, so it was reset to %s.', 'klyp-cf7-to-hubspot'),
                    HubSpotClient::DEFAULT_API_BASE
                ),
                'error'
            );
        }

        return $normalised;
    }

    /**
     * Loads the stylesheet and script for this screen only.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueue_assets(string $hook): void
    {
        if ('settings_page_' . MENU_SLUG !== $hook) {
            return;
        }

        wp_enqueue_style(
            'klyp-cf7-to-hubspot-admin',
            PLUGIN_URL . 'assets/css/admin.css',
            array('dashicons'),
            VERSION
        );

        wp_enqueue_script(
            'klyp-cf7-to-hubspot-settings',
            PLUGIN_URL . 'assets/js/settings.js',
            array(),
            VERSION,
            true
        );

        wp_localize_script('klyp-cf7-to-hubspot-settings', 'klypCf7HsSettings', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'action'     => self::AJAX_ACTION,
            'nonce'      => wp_create_nonce(self::AJAX_ACTION),
            'configured' => (new HubSpotClient())->is_configured(),
            'i18n'       => array(
                'checking' => __('Checking connection to HubSpot…', 'klyp-cf7-to-hubspot'),
                'failed'   => __('Could not reach HubSpot. Check the connection and try again.', 'klyp-cf7-to-hubspot'),
                'copy'     => __('Copy ID', 'klyp-cf7-to-hubspot'),
                'copied'   => __('Copied', 'klyp-cf7-to-hubspot'),
                'noForms'  => __('No forms found in this HubSpot account.', 'klyp-cf7-to-hubspot'),
                'unavail'  => __('Available once your account is connected.', 'klyp-cf7-to-hubspot'),
                'show'     => __('Show', 'klyp-cf7-to-hubspot'),
                'hide'     => __('Hide', 'klyp-cf7-to-hubspot'),
            ),
        ));
    }

    /**
     * Runs a live credential check for the settings screen.
     *
     * Called after the page has painted, so a slow or unreachable HubSpot never
     * delays the screen itself.
     *
     * @return void
     */
    public static function handle_test_connection(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to do this.', 'klyp-cf7-to-hubspot'),
            ), 403);
        }

        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        wp_send_json_success((new HubSpotClient())->test_connection());
    }

    /**
     * Builds a nonce-protected link that clears the HubSpot caches.
     *
     * @param string $redirect_to Optional admin URL to return to afterwards.
     * @return string
     */
    public static function flush_cache_url(string $redirect_to = ''): string
    {
        $args = array('action' => self::FLUSH_ACTION);

        if ($redirect_to !== '') {
            // add_query_arg() does not encode new values, so a nested admin URL
            // would have its own &post=/&active-tab= parsed as top-level params
            // of admin-post.php and lost from `redirect`.
            $args['redirect'] = rawurlencode($redirect_to);
        }

        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), self::FLUSH_ACTION);
    }

    /**
     * Clears every cached HubSpot form definition.
     *
     * Returns to the settings screen by default, or to a same-site admin URL
     * passed as `redirect` — the CF7 editor uses that to come back to its own tab.
     *
     * @return void
     */
    public static function handle_flush_cache(): void
    {
        // Offered from the CF7 editor panel too, which editors can open, so this
        // accepts either capability rather than wp_die()ing on them.
        if (! current_user_can(self::CAPABILITY) && ! current_user_can('wpcf7_edit_contact_forms')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'klyp-cf7-to-hubspot'));
        }

        check_admin_referer(self::FLUSH_ACTION);

        HubSpotClient::flush_form_cache();

        $redirect = isset($_GET['redirect'])
            ? wp_validate_redirect(rawurldecode(wp_unslash((string) $_GET['redirect'])), '')
            : '';

        $target = $redirect !== ''
            ? add_query_arg('klyp-cf7hs-flushed', '1', $redirect)
            : self::url(array('flushed' => '1'));

        wp_safe_redirect($target);
        exit;
    }

    /**
     * Deletes the retired public API key option.
     *
     * @return void
     */
    public static function handle_drop_legacy_key(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'klyp-cf7-to-hubspot'));
        }

        check_admin_referer(self::DROP_LEGACY_ACTION);

        delete_option(self::OPTION_LEGACY_KEY);

        wp_safe_redirect(self::url(array('legacy-removed' => '1')));
        exit;
    }

    /**
     * Renders a token as a recognisable but unusable hint.
     *
     * Shows enough for an administrator to tell which token is stored without
     * putting the credential itself in the page.
     *
     * @param string $token Stored token.
     * @return string
     */
    private static function mask(string $token): string
    {
        if (strlen($token) < 12) {
            return str_repeat('•', max(strlen($token), 4));
        }

        return substr($token, 0, 4) . str_repeat('•', 8) . substr($token, -4);
    }

    /**
     * Outputs the settings screen.
     *
     * @return void
     */
    public static function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'klyp-cf7-to-hubspot'));
        }

        $ready = (new HubSpotClient())->is_configured();

        ?>
        <div class="wrap klyp-cf7hs">

            <h1><?php esc_html_e('Klyp Contact Form 7 to HubSpot', 'klyp-cf7-to-hubspot'); ?></h1>
            <p class="klyp-cf7hs__lede">
                <?php esc_html_e('Send Contact Form 7 submissions to a HubSpot form. Connect your account here, then map fields on each individual form.', 'klyp-cf7-to-hubspot'); ?>
            </p>

            <?php
            // 'Settings saved.' and every add_settings_error() notice are
            // already printed by wp-admin/options-head.php for pages under
            // Settings, so neither is repeated here.
            self::render_flash('flushed', __('Cached HubSpot fields cleared.', 'klyp-cf7-to-hubspot'));
            self::render_flash('legacy-removed', __('The legacy HubSpot API key has been deleted.', 'klyp-cf7-to-hubspot'));
            self::render_legacy_key_notice();
            ?>

            <?php // Connection status. Filled in after load so HubSpot never delays the page. ?>
            <div id="klyp-cf7hs-status" class="klyp-cf7hs-status klyp-cf7hs-status--<?php echo $ready ? 'checking' : 'unconfigured'; ?>" aria-live="polite">
                <span class="klyp-cf7hs-status__dot" aria-hidden="true"></span>
                <span class="klyp-cf7hs-status__text">
                    <?php
                    echo $ready
                        ? esc_html__('Checking connection to HubSpot…', 'klyp-cf7-to-hubspot')
                        : esc_html__('Not connected yet. Add your portal ID and private app token below.', 'klyp-cf7-to-hubspot');
                    ?>
                </span>
                <button type="button" class="button button-small klyp-cf7hs-status__retry" <?php echo $ready ? 'hidden' : ''; ?>>
                    <?php esc_html_e('Test again', 'klyp-cf7-to-hubspot'); ?>
                </button>
            </div>

            <div class="klyp-cf7hs__columns">
                <div class="klyp-cf7hs__main">
                    <?php
                    self::render_connection_card();
                    self::render_forms_card();
                    ?>
                </div>

                <div class="klyp-cf7hs__side">
                    <?php
                    self::render_form_list_card($ready);
                    self::render_cache_card();
                    self::render_help_card();
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders a dismissible success notice for a redirect flag.
     *
     * @param string $flag    Query argument to look for.
     * @param string $message Message to show.
     * @return void
     */
    private static function render_flash(string $flag, string $message): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; each action was nonce-checked before redirecting here.
        if (empty($_GET[$flag])) {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html($message)
        );
    }

    /**
     * Warns about — and offers to delete — the retired public API key.
     *
     * @return void
     */
    private static function render_legacy_key_notice(): void
    {
        if ('' === trim((string) get_option(self::OPTION_LEGACY_KEY, ''))) {
            return;
        }

        $url = wp_nonce_url(
            add_query_arg('action', self::DROP_LEGACY_ACTION, admin_url('admin-post.php')),
            self::DROP_LEGACY_ACTION
        );

        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('A legacy HubSpot API key is still stored.', 'klyp-cf7-to-hubspot'); ?></strong>
                <?php esc_html_e('HubSpot switched off API key ("hapikey") authentication on 30 November 2022, so this value can no longer authenticate anything. Use a private app token instead.', 'klyp-cf7-to-hubspot'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url($url); ?>" class="button button-secondary">
                    <?php esc_html_e('Delete the old key', 'klyp-cf7-to-hubspot'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Card 1: the credentials form.
     *
     * @return void
     */
    private static function render_connection_card(): void
    {
        $from_constant   = HubSpotClient::token_is_constant();
        $stored_token    = (string) get_option(self::OPTION_TOKEN, '');
        $base_url        = (string) get_option(self::OPTION_BASE_URL, HubSpotClient::DEFAULT_API_BASE);
        $base_is_default = $base_url === '' || HubSpotClient::DEFAULT_API_BASE === $base_url;

        ?>
        <div class="card klyp-cf7hs-card">
            <h2 class="title"><?php esc_html_e('1. Connect your HubSpot account', 'klyp-cf7-to-hubspot'); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php settings_fields(OPTION_GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="klyp_cf7tohs_portal_id"><?php esc_html_e('Portal ID', 'klyp-cf7-to-hubspot'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="klyp_cf7tohs_portal_id"
                                name="klyp_cf7tohs_portal_id"
                                class="regular-text code"
                                value="<?php echo esc_attr((string) get_option(self::OPTION_PORTAL_ID, '')); ?>"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                placeholder="12345678"
                                aria-describedby="klyp_cf7tohs_portal_id_help"
                            >
                            <p class="description" id="klyp_cf7tohs_portal_id_help">
                                <?php esc_html_e('The numeric hub ID of your HubSpot account.', 'klyp-cf7-to-hubspot'); ?>
                                <a href="https://knowledge.hubspot.com/account-management/manage-multiple-hubspot-accounts" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e('Where do I find it?', 'klyp-cf7-to-hubspot'); ?>
                                </a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="klyp_cf7tohs_api_key_private"><?php esc_html_e('Private App token', 'klyp-cf7-to-hubspot'); ?></label>
                        </th>
                        <td>
                            <?php if ($from_constant) : ?>
                                <p class="description klyp-cf7hs-token-stored">
                                    <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                                    <?php esc_html_e('Defined by the KLYP_CF7_HUBSPOT_TOKEN constant in wp-config.php, so it cannot be edited here.', 'klyp-cf7-to-hubspot'); ?>
                                </p>
                            <?php else : ?>
                                <?php // The stored token is deliberately never rendered back into the page. ?>
                                <div class="klyp-cf7hs__secret">
                                    <input
                                        type="password"
                                        id="klyp_cf7tohs_api_key_private"
                                        name="klyp_cf7tohs_api_key_private"
                                        class="regular-text code"
                                        value=""
                                        autocomplete="new-password"
                                        spellcheck="false"
                                        aria-describedby="klyp_cf7tohs_token_help"
                                        placeholder="<?php echo $stored_token !== ''
                                            ? esc_attr__('Leave blank to keep the stored token', 'klyp-cf7-to-hubspot')
                                            : 'pat-xxx-••••'; ?>"
                                    >
                                    <button type="button" class="button klyp-cf7hs__reveal" data-target="klyp_cf7tohs_api_key_private" aria-pressed="false">
                                        <?php esc_html_e('Show', 'klyp-cf7-to-hubspot'); ?>
                                    </button>
                                </div>

                                <p class="description" id="klyp_cf7tohs_token_help">
                                    <?php esc_html_e('Create a Private App with the "forms" scope, then paste its access token.', 'klyp-cf7-to-hubspot'); ?>
                                    <a href="https://developers.hubspot.com/docs/guides/apps/private-apps/overview" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e('How do I create one?', 'klyp-cf7-to-hubspot'); ?>
                                    </a>
                                </p>

                                <?php if ($stored_token !== '') : ?>
                                    <p class="description klyp-cf7hs-token-stored">
                                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                                        <?php
                                        printf(
                                            /* translators: %s: masked token, e.g. "pat-••••••••4f2a" */
                                            esc_html__('Stored: %s', 'klyp-cf7-to-hubspot'),
                                            '<code>' . esc_html(self::mask($stored_token)) . '</code>'
                                        );
                                        ?>
                                    </p>
                                    <p>
                                        <label for="klyp_cf7tohs_clear_token">
                                            <input type="checkbox" id="klyp_cf7tohs_clear_token" name="klyp_cf7tohs_clear_token" value="1">
                                            <?php esc_html_e('Remove the stored token', 'klyp-cf7-to-hubspot'); ?>
                                        </label>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <details class="klyp-cf7hs__advanced" <?php echo $base_is_default ? '' : 'open'; ?>>
                    <summary><?php esc_html_e('Advanced', 'klyp-cf7-to-hubspot'); ?></summary>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="klyp_cf7tohs_base_url"><?php esc_html_e('API base URL', 'klyp-cf7-to-hubspot'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="url"
                                    id="klyp_cf7tohs_base_url"
                                    name="klyp_cf7tohs_base_url"
                                    class="regular-text code"
                                    value="<?php echo esc_attr($base_url); ?>"
                                    placeholder="<?php echo esc_attr(HubSpotClient::DEFAULT_API_BASE); ?>"
                                    aria-describedby="klyp_cf7tohs_base_url_help"
                                >
                                <p class="description" id="klyp_cf7tohs_base_url_help">
                                    <?php
                                    printf(
                                        /* translators: %s: default HubSpot API URL */
                                        esc_html__('Leave as %s unless HubSpot support has told you otherwise. Only hubapi.com hosts are accepted.', 'klyp-cf7-to-hubspot'),
                                        '<code>' . esc_html(HubSpotClient::DEFAULT_API_BASE) . '</code>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </details>

                <?php submit_button(__('Save settings', 'klyp-cf7-to-hubspot')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Card 2: the Contact Form 7 forms already wired up to HubSpot.
     *
     * @return void
     */
    private static function render_forms_card(): void
    {
        $forms = FormSettings::connected_forms();

        ?>
        <div class="card klyp-cf7hs-card">
            <h2 class="title"><?php esc_html_e('2. Connect a form', 'klyp-cf7-to-hubspot'); ?></h2>

            <?php if (array() === $forms) : ?>
                <p><?php esc_html_e('No contact form is sending to HubSpot yet.', 'klyp-cf7-to-hubspot'); ?></p>
                <p class="description">
                    <?php esc_html_e('Open a form, go to its HubSpot Integration tab, paste a HubSpot form ID and save. The field mapping then appears on the same tab.', 'klyp-cf7-to-hubspot'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wpcf7')); ?>" class="button">
                        <?php esc_html_e('Go to Contact Forms', 'klyp-cf7-to-hubspot'); ?>
                    </a>
                </p>
            <?php else : ?>
                <table class="wp-list-table widefat striped klyp-cf7hs-table">
                    <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Contact form', 'klyp-cf7-to-hubspot'); ?></th>
                        <th scope="col"><?php esc_html_e('HubSpot form ID', 'klyp-cf7-to-hubspot'); ?></th>
                        <th scope="col" class="klyp-cf7hs-table__num"><?php esc_html_e('Mapped fields', 'klyp-cf7-to-hubspot'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($forms as $form) : ?>
                        <?php
                        $settings = new FormSettings($form['id']);
                        $mapped   = count($settings->field_map());
                        $edit_url = admin_url('admin.php?page=wpcf7&post=' . $form['id'] . '&active-tab=klyp-hs-settings-panel');
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>">
                                    <strong><?php echo esc_html($form['title'] !== '' ? $form['title'] : __('(no title)', 'klyp-cf7-to-hubspot')); ?></strong>
                                </a>
                            </td>
                            <td><code class="klyp-cf7hs-guid"><?php echo esc_html($form['hubspot_form_id']); ?></code></td>
                            <td class="klyp-cf7hs-table__num">
                                <?php if ($mapped === 0) : ?>
                                    <span class="klyp-cf7hs-warn" title="<?php esc_attr_e('No fields are mapped, so nothing will be sent.', 'klyp-cf7-to-hubspot'); ?>">
                                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                        <?php esc_html_e('none', 'klyp-cf7-to-hubspot'); ?>
                                    </span>
                                <?php else : ?>
                                    <?php echo esc_html(number_format_i18n($mapped)); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Sidebar: the HubSpot forms available to copy an ID from.
     *
     * @param bool $ready Whether credentials are configured.
     * @return void
     */
    private static function render_form_list_card(bool $ready): void
    {
        ?>
        <div class="card klyp-cf7hs-card">
            <h2 class="title"><?php esc_html_e('Your HubSpot forms', 'klyp-cf7-to-hubspot'); ?></h2>
            <p class="description">
                <?php esc_html_e('Copy an ID here and paste it into a contact form’s HubSpot Integration tab.', 'klyp-cf7-to-hubspot'); ?>
            </p>
            <div id="klyp-cf7hs-form-list" class="klyp-cf7hs-form-list">
                <p class="klyp-cf7hs-muted">
                    <?php
                    echo $ready
                        ? esc_html__('Loading…', 'klyp-cf7-to-hubspot')
                        : esc_html__('Available once your account is connected.', 'klyp-cf7-to-hubspot');
                    ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Sidebar: cache explanation and the flush action.
     *
     * @return void
     */
    private static function render_cache_card(): void
    {
        $minutes = (int) (HubSpotClient::FORM_CACHE_TTL / MINUTE_IN_SECONDS);

        ?>
        <div class="card klyp-cf7hs-card">
            <h2 class="title"><?php esc_html_e('Cached field lists', 'klyp-cf7-to-hubspot'); ?></h2>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: number of minutes */
                    esc_html(
                        _n(
                            'HubSpot form fields are cached for %s minute. Clear the cache after changing a form in HubSpot.',
                            'HubSpot form fields are cached for %s minutes. Clear the cache after changing a form in HubSpot.',
                            $minutes,
                            'klyp-cf7-to-hubspot'
                        )
                    ),
                    esc_html(number_format_i18n($minutes))
                );
                ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::FLUSH_ACTION); ?>">
                <?php wp_nonce_field(self::FLUSH_ACTION); ?>
                <?php submit_button(__('Clear cache', 'klyp-cf7-to-hubspot'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Sidebar: the scopes the private app needs.
     *
     * @return void
     */
    private static function render_help_card(): void
    {
        $scopes = array(
            'forms'                      => __('read HubSpot form definitions so fields can be mapped', 'klyp-cf7-to-hubspot'),
            'crm.objects.contacts.read'  => __('find the contact behind a submission', 'klyp-cf7-to-hubspot'),
            'crm.objects.deals.write'    => __('create and update deals', 'klyp-cf7-to-hubspot'),
            'crm.objects.deals.read'     => __('pick the pipeline and stage from a list instead of typing IDs', 'klyp-cf7-to-hubspot'),
        );

        ?>
        <div class="card klyp-cf7hs-card">
            <h2 class="title"><?php esc_html_e('Private app scopes', 'klyp-cf7-to-hubspot'); ?></h2>
            <p class="description">
                <?php esc_html_e('Grant these to the private app, or parts of the integration will fail silently.', 'klyp-cf7-to-hubspot'); ?>
            </p>
            <ul class="klyp-cf7hs-scopes">
                <?php foreach ($scopes as $scope => $why) : ?>
                    <li>
                        <code><?php echo esc_html($scope); ?></code>
                        <span class="klyp-cf7hs-muted"><?php echo esc_html($why); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="description">
                <?php esc_html_e('Only the "forms" scope is needed if you are not creating deals.', 'klyp-cf7-to-hubspot'); ?>
            </p>
        </div>
        <?php
    }
}
