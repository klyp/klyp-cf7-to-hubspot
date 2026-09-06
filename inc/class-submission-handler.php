<?php
/**
 * Pushes Contact Form 7 submissions into HubSpot.
 *
 * @package Klyp\CF7ToHubspot
 */

declare(strict_types=1);

namespace Klyp\CF7ToHubspot;

defined('ABSPATH') || exit;

/**
 * Handles the runtime side of the integration.
 */
final class SubmissionHandler
{
    /** Cron hook that finishes the CRM work after the visitor has been answered. */
    public const DEAL_EVENT = 'klyp_cf7tohs_create_deal';

    /**
     * How long to wait before creating the deal.
     *
     * HubSpot processes Forms API submissions asynchronously, so a contact
     * created by this submission does not exist in the CRM object API for a
     * short while. Looking it up inline found nothing for every first-time
     * visitor, which silently skipped their deal.
     */
    private const DEAL_DELAY = 120;

    /**
     * Registers the runtime hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        // wpcf7_before_send_mail runs only for submissions that already passed
        // validation and every spam filter, and gives us a supported way to abort.
        add_action('wpcf7_before_send_mail', array(self::class, 'handle'), 10, 3);

        // Non-AJAX submissions never see the JSON feedback payload, so the
        // redirect has to happen server-side for them.
        add_action('wpcf7_submit', array(self::class, 'maybe_redirect'), 10, 2);

        // Contact Form 7 calls wpcf7_enqueue_scripts() from wp_enqueue_scripts
        // whenever wpcf7_load_js() is true (the default), so this fires on every
        // front-end page, not only ones with a form. Registering here and
        // enqueuing per-form below keeps it off pages that cannot use it.
        add_action('wpcf7_enqueue_scripts', array(self::class, 'register_frontend_script'));
        add_filter('wpcf7_form_elements', array(self::class, 'enqueue_for_rendered_form'));

        add_action(self::DEAL_EVENT, array(self::class, 'run_deal_event'), 10, 1);
    }

    /**
     * Sends the submission to HubSpot and queues the deal work.
     *
     * @param \WPCF7_ContactForm $contact_form The submitted form.
     * @param bool               $abort        Set to true to stop the mail from being sent.
     * @param \WPCF7_Submission  $submission   The submission.
     * @return void
     */
    public static function handle(\WPCF7_ContactForm $contact_form, bool &$abort, \WPCF7_Submission $submission): void
    {
        $form_id  = (int) $contact_form->id();
        $settings = new FormSettings($form_id);

        if (! $settings->is_enabled()) {
            return;
        }

        $client = new HubSpotClient();

        // The Forms API endpoint is unauthenticated and needs only the portal ID.
        // Gating this on a private app token as well would stop a 1.x install that
        // never had one from sending anything at all.
        if (! $client->can_submit_forms()) {
            $settings->record_error(
                __('No HubSpot portal ID is configured, so nothing was sent.', 'klyp-cf7-to-hubspot')
            );
            log_message(sprintf('Form %d is mapped to HubSpot but no portal ID is configured.', $form_id));

            return;
        }

        // get_posted_data() is Contact Form 7's own normalised view of the
        // submission, which avoids reading $_POST directly.
        $posted = $submission->get_posted_data();
        $posted = is_array($posted) ? $posted : array();

        $result = $client->submit_form(
            $settings->hubspot_form_id(),
            self::build_fields($settings, $posted),
            self::build_context($submission)
        );

        if (! $result['success']) {
            // HubSpot's own validation message is shown to the visitor when it
            // names a field this form maps. Anything else is on their side and
            // must not cost the site a lead, but it is still recorded so the
            // failure is visible in the editor rather than silent.
            $field_error = self::find_mapped_field_error($settings, $result['errors']);

            $settings->record_error($result['message']);
            log_message(sprintf(
                'Form %d submission rejected by HubSpot (HTTP %d): %s',
                $form_id,
                $result['status'],
                $result['message']
            ));

            if ($field_error !== null) {
                $abort = true;
                $submission->set_status('aborted');
                $submission->set_response($field_error);
            }

            return;
        }

        $settings->clear_error();

        $redirect = $settings->redirect_url();

        if ($redirect !== '') {
            // add_result_props() is merged into the REST feedback payload by CF7
            // itself, so no separate response filter and no request-scoped state.
            $submission->add_result_props(array('formRedirect' => $redirect));
        }

        self::queue_deal($settings, $posted, $form_id);
    }

    /**
     * Redirects non-AJAX submissions, which never read the JSON payload.
     *
     * @param \WPCF7_ContactForm  $contact_form The submitted form.
     * @param array<string,mixed> $result       Submission result.
     * @return void
     */
    public static function maybe_redirect(\WPCF7_ContactForm $contact_form, array $result): void
    {
        if (\WPCF7_Submission::is_restful()) {
            return;
        }

        if (($result['status'] ?? '') !== 'mail_sent') {
            return;
        }

        $redirect = (new FormSettings((int) $contact_form->id()))->redirect_url();

        if ($redirect === '') {
            return;
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Builds the HubSpot Forms API field list from the mapped CF7 values.
     *
     * @param FormSettings        $settings Form configuration.
     * @param array<string,mixed> $posted   Submitted values.
     * @return array<int,array{name:string,value:string}>
     */
    private static function build_fields(FormSettings $settings, array $posted): array
    {
        $fields = array();

        foreach ($settings->field_map() as $row) {
            if (! array_key_exists($row['cf7'], $posted)) {
                continue;
            }

            $fields[] = array(
                'name'  => $row['hubspot'],
                'value' => self::stringify($posted[$row['cf7']]),
            );
        }

        return $fields;
    }

    /**
     * Flattens a posted value to the string HubSpot expects.
     *
     * Uses Contact Form 7's own flattener, which recurses into nested arrays
     * (its posted-data filters and third-party tags can produce them) and keeps
     * the line breaks a textarea deliberately preserved.
     *
     * @param mixed $value Raw posted value.
     * @return string
     */
    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (function_exists('wpcf7_flat_join')) {
            return wpcf7_flat_join($value, array('separator' => ';'));
        }

        if (is_array($value)) {
            $flat = array();

            array_walk_recursive($value, static function ($item) use (&$flat): void {
                if (is_scalar($item) && (string) $item !== '') {
                    $flat[] = trim((string) $item);
                }
            });

            return implode(';', $flat);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Collects the HubSpot tracking context for this request.
     *
     * Reads Contact Form 7's own record of the submission rather than guessing
     * from the referrer: get_meta('url') is the request URL it validated and
     * container_post_id is the post the form was actually embedded in, so this
     * stays correct for archives, the front page and widget areas, and costs no
     * extra query.
     *
     * @param \WPCF7_Submission $submission The submission.
     * @return array<string,string>
     */
    private static function build_context(\WPCF7_Submission $submission): array
    {
        $context = array();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Sanitised on the next line.
        $hutk = isset($_COOKIE['hubspotutk']) ? sanitize_text_field(wp_unslash((string) $_COOKIE['hubspotutk'])) : '';

        if ($hutk !== '') {
            $context['hutk'] = $hutk;
        }

        $url = (string) $submission->get_meta('url');

        if ($url !== '') {
            $context['pageUri'] = $url;
        }

        $post_id = (int) $submission->get_meta('container_post_id');

        if ($post_id > 0) {
            // get_the_title() runs the_title filters (wptexturize, convert_chars),
            // which would otherwise send "Sales &#038; Support" to HubSpot.
            $title = html_entity_decode(
                wp_strip_all_tags(get_the_title($post_id)),
                ENT_QUOTES | ENT_HTML5,
                get_bloginfo('charset') ?: 'UTF-8'
            );

            if ($title !== '') {
                $context['pageName'] = $title;
            }
        }

        $ip = (string) $submission->get_meta('remote_ip');

        if ($ip !== '') {
            $context['ipAddress'] = $ip;
        }

        return $context;
    }

    /**
     * Finds a HubSpot validation error that refers to a field this form maps.
     *
     * @param FormSettings     $settings Form configuration.
     * @param array<int,mixed> $errors   HubSpot `errors` array.
     * @return string|null The message to show the visitor, or null.
     */
    private static function find_mapped_field_error(FormSettings $settings, array $errors): ?string
    {
        if ($errors === array()) {
            return null;
        }

        $mapped = array_column($settings->field_map(), 'hubspot');

        foreach ($errors as $error) {
            $message = '';
            $type    = '';

            if (is_array($error)) {
                $message = (string) ($error['message'] ?? '');
                $type    = (string) ($error['errorType'] ?? '');
            } elseif (is_string($error)) {
                $message = $error;
            }

            if ($message === '') {
                continue;
            }

            // errorType is a stable identifier; the message wording is not, and is
            // localised by HubSpot, so it is only used to pick out the field name.
            $field_level = in_array($type, array('INVALID_EMAIL', 'REQUIRED_FIELD', 'INVALID_NUMBER'), true);
            $names_field = preg_match('/fields\.([A-Za-z0-9_.\-]+)/', $message, $matches) === 1
                && in_array($matches[1], $mapped, true);

            if ($field_level || $names_field) {
                /**
                 * Filters the HubSpot validation message shown to the visitor.
                 *
                 * @param string $message Message from HubSpot.
                 * @param string $type    HubSpot errorType, may be empty.
                 */
                return (string) apply_filters(
                    'klyp_cf7tohs_field_error_message',
                    wp_strip_all_tags($message),
                    $type
                );
            }
        }

        return null;
    }

    /**
     * Schedules the deal work if this submission qualifies for one.
     *
     * The dealbreaker rules depend on the submitted values, so they are evaluated
     * now; everything else is re-read from post meta when the event runs.
     *
     * @param FormSettings        $settings Form configuration.
     * @param array<string,mixed> $posted   Submitted values.
     * @param int                 $form_id  Contact Form 7 post ID.
     * @return void
     */
    private static function queue_deal(FormSettings $settings, array $posted, int $form_id): void
    {
        if ($settings->deals_disabled()) {
            return;
        }

        $cf7_email_field = $settings->cf7_email_field();

        if ($cf7_email_field === '' || $settings->hubspot_email_field() === '') {
            return;
        }

        foreach ($settings->dealbreakers() as $rule) {
            $value = self::stringify($posted[$rule['field']] ?? '');

            if ($value !== '' && $value === $rule['value']) {
                return;
            }
        }

        $email = self::stringify($posted[$cf7_email_field] ?? '');

        if ($email === '' || ! is_email($email)) {
            return;
        }

        // The nonce keeps two submissions from the same address inside WordPress's
        // 10-minute duplicate-event window from collapsing into one deal.
        $scheduled = wp_schedule_single_event(
            time() + self::DEAL_DELAY,
            self::DEAL_EVENT,
            array(
                array(
                    'form_id' => $form_id,
                    'email'   => $email,
                    'nonce'   => wp_generate_uuid4(),
                )
            )
        );

        if ($scheduled === false) {
            log_message(sprintf('Could not schedule deal creation for form %d.', $form_id));
        }
    }

    /**
     * Creates and names the deal. Runs from WP-Cron, off the visitor's request.
     *
     * @param array<string,mixed> $args Scheduled payload.
     * @return void
     */
    public static function run_deal_event(array $args = array()): void
    {
        $form_id = (int) ($args['form_id'] ?? 0);
        $email   = (string) ($args['email'] ?? '');

        if ($form_id <= 0 || $email === '' || ! is_email($email)) {
            return;
        }

        $settings = new FormSettings($form_id);

        // Re-checked here because an admin may have turned deals off in the
        // window between the submission and this event.
        if (! $settings->is_enabled() || $settings->deals_disabled()) {
            return;
        }

        $client = new HubSpotClient();

        if (! $client->is_configured()) {
            return;
        }

        $contact_id = $client->find_contact_id_by_email($email);

        if ($contact_id === null) {
            log_message(sprintf(
                'No HubSpot contact found for a form %d submission, so no deal was created.',
                $form_id
            ));

            return;
        }

        $deal_id = $client->create_deal(
            array(
                'dealname'  => $email,
                'pipeline'  => $settings->pipeline_id(),
                'dealstage' => $settings->stage_id(),
            ),
            $contact_id
        );

        if ($deal_id === null) {
            return;
        }

        // The deal ID only exists after creation, so the name is finalised here.
        // This keeps the "email - dealId" naming 1.x produced.
        $client->update_deal($deal_id, array(
            'dealname' => $email . ' - ' . $deal_id,
        ));
    }

    /**
     * Registers the redirect listener without enqueuing it.
     *
     * @return void
     */
    public static function register_frontend_script(): void
    {
        wp_register_script(
            'klyp-cf7-to-hubspot-redirect',
            PLUGIN_URL . 'assets/js/redirect.js',
            array('contact-form-7'),
            VERSION,
            true
        );
    }

    /**
     * Enqueues the listener only for a rendered form that has a redirect set.
     *
     * @param string $elements The form HTML.
     * @return string Unchanged.
     */
    public static function enqueue_for_rendered_form(string $elements): string
    {
        $contact_form = \WPCF7_ContactForm::get_current();

        if (! $contact_form instanceof \WPCF7_ContactForm) {
            return $elements;
        }

        $settings = new FormSettings((int) $contact_form->id());

        if ($settings->is_enabled() && $settings->redirect_url() !== '') {
            wp_enqueue_script('klyp-cf7-to-hubspot-redirect');
        }

        return $elements;
    }
}
