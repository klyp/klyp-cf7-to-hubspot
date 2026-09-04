<?php
/**
 * Per-form HubSpot settings stored as Contact Form 7 post meta.
 *
 * @package Klyp\CF7ToHubspot
 */

declare(strict_types=1);

namespace Klyp\CF7ToHubspot;

defined('ABSPATH') || exit;

/**
 * Reads, sanitises and writes the HubSpot settings attached to a CF7 form.
 *
 * The meta keys are unchanged from 1.x so existing installs keep their mappings.
 */
final class FormSettings
{
    public const META_FORM_ID          = '_klyp-cf7-to-hubspot-form-id';
    public const META_REDIRECT         = '_klyp-cf7-to-hubspot-form-redirect';
    public const META_CF7_EMAIL_FIELD  = '_klyp-cf7-to-hubspot-cf7-email-field';
    public const META_HS_EMAIL_FIELD   = '_klyp-cf7-to-hubspot-email-field';
    public const META_PIPELINE_ID      = '_klyp-cf7-to-hubspot-pipeline-id';
    public const META_STAGE_ID         = '_klyp-cf7-to-hubspot-stage-id';
    public const META_CF_MAP_FIELDS    = '_klyp-cf7-to-hubspot-cf-map-fields';
    public const META_HS_MAP_FIELDS    = '_klyp-cf7-to-hubspot-hs-map-fields';
    public const META_DEALBREAKER_FIELD = '_klyp-cf7-to-hubspot-dealbreaker-field';
    public const META_DEALBREAKER_VALUE = '_klyp-cf7-to-hubspot-dealbreaker-value';

    /**
     * Explicit '1' / '0' deal switch, written by upgrade_to_200().
     *
     * Replaces the 1.x META_DEALBREAKER_ALLOW flag, whose inverted meaning
     * ('true' = never create) had no way to express "on" and forced the editor
     * to guess from whether other fields were filled in.
     */
    public const META_CREATE_DEALS = '_klyp-cf7-to-hubspot-create-deals';

    /** Retired 1.x flag. Read only by the upgrade routine. */
    public const META_DEALBREAKER_ALLOW = '_klyp-cf7-to-hubspot-dealbreaker-allow';

    /** Last HubSpot failure for this form, surfaced in the editor. */
    public const META_LAST_ERROR = '_klyp-cf7-to-hubspot-last-error';

    private int $form_id;

    /**
     * @param int $form_id Contact Form 7 post ID.
     */
    public function __construct(int $form_id)
    {
        $this->form_id = $form_id;
    }

    /**
     * Reads a scalar meta value as a trimmed string.
     *
     * @param string $key Meta key.
     * @return string
     */
    private function scalar(string $key): string
    {
        if ($this->form_id <= 0) {
            return '';
        }

        $value = get_post_meta($this->form_id, $key, true);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Reads a meta value as a list of strings.
     *
     * Post meta returns an empty string when unset, which is why 1.x crashed with
     * `count(): Argument #1 must be of type Countable|array` on PHP 8.
     *
     * @param string $key Meta key.
     * @return array<int,string>
     */
    private function listing(string $key): array
    {
        if ($this->form_id <= 0) {
            return array();
        }

        $value = get_post_meta($this->form_id, $key, true);

        if (! is_array($value)) {
            return array();
        }

        return array_values(array_map(
            static fn ($item): string => is_scalar($item) ? (string) $item : '',
            $value
        ));
    }

    /**
     * @return string The HubSpot form GUID this CF7 form submits to.
     */
    public function hubspot_form_id(): string
    {
        return $this->scalar(self::META_FORM_ID);
    }

    /**
     * @return bool True when a HubSpot form GUID has been configured.
     */
    public function is_enabled(): bool
    {
        return $this->hubspot_form_id() !== '';
    }

    /**
     * Returns the configured redirect exactly as stored, for editing.
     *
     * @return string
     */
    public function redirect_raw(): string
    {
        return $this->scalar(self::META_REDIRECT);
    }

    /**
     * Returns the post-submission redirect, if it is a URL we are willing to send users to.
     *
     * @return string Absolute or root-relative URL, or an empty string.
     */
    public function redirect_url(): string
    {
        $raw = $this->redirect_raw();

        if ($raw === '') {
            return '';
        }

        // Resolve any relative value against the site, not against the current
        // request: during an AJAX submission REQUEST_URI is the CF7 REST route,
        // so leaving this to wp_validate_redirect() produced /wp-json/... targets.
        if (! str_starts_with($raw, '//') && wp_parse_url($raw, PHP_URL_SCHEME) === null) {
            $raw = home_url('/' . ltrim($raw, '/'));
        }

        // One gate for every value, so the allowed_redirect_hosts filter and any
        // future hardening in core apply uniformly.
        $validated = wp_validate_redirect($raw, '');

        return is_string($validated) ? $validated : '';
    }

    /**
     * @return string CF7 field name holding the submitter's email address.
     */
    public function cf7_email_field(): string
    {
        return $this->scalar(self::META_CF7_EMAIL_FIELD);
    }

    /**
     * @return string HubSpot field name holding the email address.
     */
    public function hubspot_email_field(): string
    {
        return $this->scalar(self::META_HS_EMAIL_FIELD);
    }

    /**
     * @return string HubSpot pipeline ID used for created deals.
     */
    public function pipeline_id(): string
    {
        return $this->scalar(self::META_PIPELINE_ID);
    }

    /**
     * @return string HubSpot deal stage ID used for created deals.
     */
    public function stage_id(): string
    {
        return $this->scalar(self::META_STAGE_ID);
    }

    /**
     * Pairs the CF7 and HubSpot mapping arrays, discarding incomplete rows.
     *
     * @return array<int,array{cf7:string,hubspot:string}>
     */
    public function field_map(): array
    {
        $cf7     = $this->listing(self::META_CF_MAP_FIELDS);
        $hubspot = $this->listing(self::META_HS_MAP_FIELDS);
        $map     = array();

        foreach ($cf7 as $index => $cf7_field) {
            $hs_field = $hubspot[$index] ?? '';

            if ($cf7_field === '' || $hs_field === '') {
                continue;
            }

            $map[] = array(
                'cf7'     => $cf7_field,
                'hubspot' => $hs_field,
            );
        }

        return $map;
    }

    /**
     * @return bool True when deals must never be created for this form.
     */
    public function deals_disabled(): bool
    {
        return ! $this->deals_enabled();
    }

    /**
     * Reads the explicit deal switch, falling back to the retired 1.x flag.
     *
     * The fallback keeps forms behaving correctly between the upgrade running
     * and this form next being saved.
     *
     * @return bool
     */
    public function deals_enabled(): bool
    {
        $explicit = $this->scalar(self::META_CREATE_DEALS);

        if ($explicit !== '') {
            return $explicit === '1';
        }

        return $this->scalar(self::META_DEALBREAKER_ALLOW) !== 'true';
    }

    /**
     * @return array{message:string,time:int} Last HubSpot failure, message empty when none.
     */
    public function last_error(): array
    {
        $stored = $this->form_id > 0 ? get_post_meta($this->form_id, self::META_LAST_ERROR, true) : '';

        if (! is_array($stored)) {
            return array('message' => '', 'time' => 0);
        }

        return array(
            'message' => is_scalar($stored['message'] ?? '') ? (string) $stored['message'] : '',
            'time'    => (int) ($stored['time'] ?? 0),
        );
    }

    /**
     * Records a HubSpot failure so it is visible in the editor, not only in a log.
     *
     * @param string $message Failure description.
     * @return void
     */
    public function record_error(string $message): void
    {
        if ($this->form_id <= 0 || $message === '') {
            return;
        }

        update_post_meta($this->form_id, self::META_LAST_ERROR, array(
            'message' => wp_slash(sanitize_text_field($message)),
            'time'    => time(),
        ));
    }

    /**
     * Clears a recorded failure after a successful submission.
     *
     * @return void
     */
    public function clear_error(): void
    {
        if ($this->form_id > 0 && $this->last_error()['message'] !== '') {
            delete_post_meta($this->form_id, self::META_LAST_ERROR);
        }
    }

    /**
     * Pairs the deal-breaker condition arrays, discarding incomplete rows.
     *
     * @return array<int,array{field:string,value:string}>
     */
    public function dealbreakers(): array
    {
        $fields = $this->listing(self::META_DEALBREAKER_FIELD);
        $values = $this->listing(self::META_DEALBREAKER_VALUE);
        $rules  = array();

        foreach ($fields as $index => $field) {
            $value = $values[$index] ?? '';

            if ($field === '' || $value === '') {
                continue;
            }

            $rules[] = array(
                'field' => $field,
                'value' => $value,
            );
        }

        return $rules;
    }

    /**
     * Extracts and sanitises this plugin's settings from a submitted CF7 editor form.
     *
     * @param array<string,mixed> $input Unslashed request data handed over by Contact Form 7.
     * @return array<string,mixed> Meta key => value, ready to persist.
     */
    public static function sanitize_submitted(array $input): array
    {
        $text = static function (string $key) use ($input): string {
            $value = $input[ltrim($key, '_')] ?? '';

            return is_scalar($value) ? sanitize_text_field(trim((string) $value)) : '';
        };

        $text_list = static function (string $key) use ($input): array {
            $value = $input[ltrim($key, '_')] ?? array();

            if (! is_array($value)) {
                return array();
            }

            return array_values(array_map(
                static fn ($item): string => is_scalar($item) ? sanitize_text_field((string) $item) : '',
                $value
            ));
        };

        // The panel collapses to just the form ID until one is set, so only that
        // field is on screen. Saving then must not wipe the rest of the config.
        if ('full' !== ($input['klyp-cf7-to-hubspot-panel'] ?? '')) {
            return array(
                self::META_FORM_ID => $text(self::META_FORM_ID),
            );
        }

        $redirect = $input['klyp-cf7-to-hubspot-form-redirect'] ?? '';
        $redirect = is_scalar($redirect) ? self::sanitize_redirect(trim((string) $redirect)) : '';

        return array(
            self::META_FORM_ID           => $text(self::META_FORM_ID),
            self::META_REDIRECT          => $redirect,
            self::META_CF7_EMAIL_FIELD   => $text(self::META_CF7_EMAIL_FIELD),
            self::META_HS_EMAIL_FIELD    => $text(self::META_HS_EMAIL_FIELD),
            self::META_PIPELINE_ID       => $text(self::META_PIPELINE_ID),
            self::META_STAGE_ID          => $text(self::META_STAGE_ID),
            self::META_CF_MAP_FIELDS     => $text_list(self::META_CF_MAP_FIELDS),
            self::META_HS_MAP_FIELDS     => $text_list(self::META_HS_MAP_FIELDS),
            // Explicit '1'/'0' so the editor renders straight from storage rather
            // than inferring intent, and so the retired inverted flag can go.
            self::META_CREATE_DEALS      => isset($input['klyp-cf7-to-hubspot-create-deals']) ? '1' : '0',
            self::META_DEALBREAKER_ALLOW => '',
            self::META_DEALBREAKER_FIELD => $text_list(self::META_DEALBREAKER_FIELD),
            self::META_DEALBREAKER_VALUE => $text_list(self::META_DEALBREAKER_VALUE),
        );
    }

    /**
     * Normalises a submitted redirect without mangling site-relative paths.
     *
     * esc_url_raw() prepends "http://" to anything without a scheme that does
     * not start with / # or ?, which silently turned "thank-you/" into the
     * unreachable "http://thank-you/".
     *
     * @param string $value Trimmed submitted value.
     * @return string
     */
    private static function sanitize_redirect(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return esc_url_raw($value);
        }

        // A bare path or host-looking value is treated as being on this site.
        if (wp_parse_url($value, PHP_URL_SCHEME) === null && ! str_starts_with($value, '//')) {
            return esc_url_raw(home_url('/' . ltrim($value, '/')));
        }

        return esc_url_raw($value);
    }

    /**
     * Persists sanitised settings, deleting rather than storing empty values.
     *
     * @param int                 $form_id Contact Form 7 post ID.
     * @param array<string,mixed> $values  Output of {@see self::sanitize_submitted()}.
     * @return void
     */
    public static function save(int $form_id, array $values): void
    {
        if ($form_id <= 0) {
            return;
        }

        foreach ($values as $meta_key => $value) {
            if ($value === '' || $value === array()) {
                delete_post_meta($form_id, $meta_key);
                continue;
            }

            // Contact Form 7 hands us wp_unslash($_REQUEST), and update_metadata()
            // unslashes again, so a value containing a backslash would lose one
            // level of escaping on every save.
            update_post_meta($form_id, $meta_key, wp_slash($value));
        }
    }

    /**
     * Lists every Contact Form 7 form that has a HubSpot form GUID mapped.
     *
     * @return array<int,array{id:int,title:string,hubspot_form_id:string}>
     */
    public static function connected_forms(): array
    {
        $posts = get_posts(array(
            'post_type'        => 'wpcf7_contact_form',
            'post_status'      => 'any',
            'numberposts'      => 100,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => false,
            'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'     => self::META_FORM_ID,
                    'compare' => 'EXISTS',
                ),
            ),
        ));

        $forms = array();

        foreach ($posts as $post) {
            $settings = new self((int) $post->ID);

            if (! $settings->is_enabled()) {
                continue;
            }

            $forms[] = array(
                'id'              => (int) $post->ID,
                'title'           => (string) $post->post_title,
                'hubspot_form_id' => $settings->hubspot_form_id(),
            );
        }

        return $forms;
    }
}
