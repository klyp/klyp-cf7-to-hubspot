<?php
/**
 * HubSpot REST client.
 *
 * @package Klyp\CF7ToHubspot
 */

declare(strict_types=1);

namespace Klyp\CF7ToHubspot;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around the HubSpot APIs used by this plugin.
 *
 * Authentication is private-app-token only. HubSpot sunset the `hapikey`
 * query-string API key on 30 November 2022, so the legacy key mode has been
 * removed rather than kept as a fallback that can only ever fail.
 */
final class HubSpotClient
{
    /** Default HubSpot REST host. */
    public const DEFAULT_API_BASE = 'https://api.hubapi.com/';

    /** Unauthenticated Forms submission host, in the default (NA) region. */
    public const FORMS_SUBMIT_BASE = 'https://api.hsforms.com/submissions/v3/integration/submit/';

    /** Only hosts under this domain may receive the private app token. */
    private const ALLOWED_API_HOST_SUFFIX = 'hubapi.com';

    /** Transient prefix for cached HubSpot responses. */
    private const CACHE_PREFIX = 'klyp_cf7hs_';

    /**
     * Option holding a cache generation counter.
     *
     * Baked into every cache key, so flushing is a single increment. The 1.x-era
     * index of transient keys needed a read plus a write on every cache miss and
     * silently truncated past 200 entries, which made "Clear cache" incomplete.
     */
    private const CACHE_GENERATION = 'klyp_cf7tohs_cache_generation';

    /** How long a HubSpot response stays cached. */
    public const FORM_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

    /** Forms per request when listing an account's forms. */
    private const FORMS_PAGE_SIZE = 100;

    /** Safety stop when following HubSpot's form paging. */
    private const MAX_FORM_PAGES = 20;

    /** HUBSPOT_DEFINED association type for deal -> contact. */
    private const ASSOCIATION_DEAL_TO_CONTACT = 3;

    private string $token;

    private string $portal_id;

    private string $api_base;

    public function __construct()
    {
        $this->token     = self::get_token();
        $this->portal_id = trim((string) get_option(SettingsPage::OPTION_PORTAL_ID, ''));
        $this->api_base  = self::normalise_api_base((string) get_option(SettingsPage::OPTION_BASE_URL, ''));
    }

    /**
     * Returns the Forms submission host for the configured region.
     *
     * The REST host and the Forms host move together: a portal on HubSpot's EU
     * infrastructure uses api-eu1.hubapi.com and api-eu1.hsforms.com. Deriving
     * one from the other stops an EU portal from reading its forms correctly
     * while silently posting every submission to the NA host.
     *
     * @return string Trailing-slashed submission base URL.
     */
    private function forms_submit_base(): string
    {
        $host = (string) wp_parse_url($this->api_base, PHP_URL_HOST);

        if (preg_match('/^api-([a-z0-9]+)\.hubapi\.com$/', $host, $matches) === 1) {
            return sprintf(
                'https://api-%s.hsforms.com/submissions/v3/integration/submit/',
                $matches[1]
            );
        }

        return self::FORMS_SUBMIT_BASE;
    }

    /**
     * Resolves the private app token.
     *
     * A `KLYP_CF7_HUBSPOT_TOKEN` constant in wp-config.php takes precedence, which
     * keeps the secret out of the database and out of database exports entirely.
     *
     * @return string
     */
    public static function get_token(): string
    {
        if (defined('KLYP_CF7_HUBSPOT_TOKEN') && is_string(KLYP_CF7_HUBSPOT_TOKEN)) {
            return trim(KLYP_CF7_HUBSPOT_TOKEN);
        }

        return trim((string) get_option('klyp_cf7tohs_api_key_private', ''));
    }

    /**
     * Reports whether the token is supplied by a constant rather than the database.
     *
     * @return bool
     */
    public static function token_is_constant(): bool
    {
        return defined('KLYP_CF7_HUBSPOT_TOKEN') && is_string(KLYP_CF7_HUBSPOT_TOKEN) && trim(KLYP_CF7_HUBSPOT_TOKEN) !== '';
    }

    /**
     * Constrains the REST base URL to HubSpot, falling back to the default.
     *
     * Without this an attacker who can write options (or a mistyped setting) would
     * send the private app token to an arbitrary host.
     *
     * @param string $base Candidate base URL.
     * @return string A trailing-slashed, https HubSpot URL.
     */
    public static function normalise_api_base(string $base): string
    {
        $base = trim($base);

        if ($base === '') {
            return self::DEFAULT_API_BASE;
        }

        $host = wp_parse_url($base, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return self::DEFAULT_API_BASE;
        }

        $host = strtolower($host);

        if ($host !== self::ALLOWED_API_HOST_SUFFIX && ! str_ends_with($host, '.' . self::ALLOWED_API_HOST_SUFFIX)) {
            return self::DEFAULT_API_BASE;
        }

        return 'https://' . $host . '/';
    }

    /**
     * @return bool True when the authenticated APIs (fields, contacts, deals) can be used.
     */
    public function is_configured(): bool
    {
        return $this->token !== '' && $this->portal_id !== '';
    }

    /**
     * Reports whether a form submission can be sent.
     *
     * The Forms endpoint is unauthenticated, so this needs only the portal ID.
     * Requiring a token here would stop a 1.x install that never had one — the
     * legacy hapikey installs — from sending anything at all after upgrading.
     *
     * @return bool
     */
    public function can_submit_forms(): bool
    {
        return $this->portal_id !== '';
    }

    /**
     * Performs an authenticated request against the HubSpot REST API.
     *
     * @param string     $method Uppercase HTTP method.
     * @param string     $path   Path relative to the API base, without a leading slash.
     * @param array|null $body   Optional JSON body.
     * @return array{status:int,body:array,error:string} Normalised response.
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->token === '') {
            return array(
                'status' => 0,
                'body'   => array(),
                'error'  => __('No HubSpot private app token is configured.', 'klyp-cf7-to-hubspot'),
            );
        }

        $args = array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->api_base . ltrim($path, '/'), $args);

        return self::normalise_response($response);
    }

    /**
     * Converts a wp_remote_* result into a predictable shape.
     *
     * @param array|\WP_Error $response Raw wp_remote_* return value.
     * @return array{status:int,body:array,error:string}
     */
    private static function normalise_response(array|\WP_Error $response): array
    {
        if (is_wp_error($response)) {
            return array(
                'status' => 0,
                'body'   => array(),
                'error'  => $response->get_error_message(),
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $body   = array();

        if ($raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return array(
            'status' => $status,
            'body'   => $body,
            'error'  => '',
        );
    }

    /**
     * Fetches a HubSpot form definition, flattened to the fields we can map.
     *
     * Results are cached because the CF7 editor panel renders one `<select>`
     * per mapping row and would otherwise refetch on every admin page load.
     *
     * @param string $form_id HubSpot form GUID.
     * @return array<int,array{name:string,label:string,type:string}> Mappable fields, possibly empty.
     */
    public function get_form_fields(string $form_id): array
    {
        $form_id = trim($form_id);

        if ($form_id === '' || ! $this->is_configured()) {
            return array();
        }

        $cache_key = $this->cache_key('form', $form_id);
        $cached    = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request('GET', 'marketing/v3/forms/' . rawurlencode($form_id));

        if ($response['status'] !== 200) {
            // Only a real answer from HubSpot is worth remembering. Caching a
            // transport failure (status 0) told the admin their form ID or scope
            // was wrong for the next minute when the truth was a network blip.
            if ($response['status'] > 0) {
                set_transient($cache_key, array(), MINUTE_IN_SECONDS);
            }

            log_message(sprintf(
                'Failed to load HubSpot form %s (HTTP %d) %s',
                $form_id,
                $response['status'],
                $response['error']
            ));

            return array();
        }

        $fields = array();

        foreach ($response['body']['fieldGroups'] ?? array() as $group) {
            foreach ($group['fields'] ?? array() as $field) {
                $name = (string) ($field['name'] ?? '');

                if ($name === '') {
                    continue;
                }

                $fields[] = array(
                    'name'  => $name,
                    'label' => (string) ($field['label'] ?? $name),
                    'type'  => (string) ($field['fieldType'] ?? ($field['type'] ?? '')),
                );
            }
        }

        set_transient($cache_key, $fields, self::FORM_CACHE_TTL);

        return $fields;
    }

    /**
     * Builds a cache key scoped to the current credentials and cache generation.
     *
     * The token and host are part of the key, so rotating either cannot serve a
     * previous portal's data, and the generation counter makes flushing O(1).
     *
     * @param string $kind Cache bucket, e.g. 'form' or 'pipelines'.
     * @param string $id   Optional discriminator within the bucket.
     * @return string
     */
    private function cache_key(string $kind, string $id = ''): string
    {
        return self::CACHE_PREFIX . $kind . '_' . md5(implode('|', array(
            (string) get_option(self::CACHE_GENERATION, '0'),
            $this->api_base,
            $this->portal_id,
            $this->token,
            $id,
        )));
    }

    /**
     * Invalidates every cached HubSpot response by moving the generation on.
     *
     * @return void
     */
    public static function flush_form_cache(): void
    {
        $generation = (int) get_option(self::CACHE_GENERATION, 0);

        update_option(self::CACHE_GENERATION, (string) ($generation + 1), false);
    }

    /**
     * Submits mapped values to the HubSpot Forms API.
     *
     * This endpoint lives on api.hsforms.com and is deliberately unauthenticated,
     * so the private app token is never attached to it.
     *
     * @param string                                     $form_id HubSpot form GUID.
     * @param array<int,array{name:string,value:string}> $fields  Field name/value pairs.
     * @param array<string,string>                       $context Tracking context (hutk, pageUri, pageName).
     * @return array{success:bool,status:int,message:string,errors:array<int,array>} Outcome.
     */
    public function submit_form(string $form_id, array $fields, array $context = array()): array
    {
        $generic = __('There is something wrong while processing your request. Please try again later.', 'klyp-cf7-to-hubspot');

        if (trim($form_id) === '' || $this->portal_id === '') {
            return array(
                'success' => false,
                'status'  => 0,
                'message' => $generic,
                'errors'  => array(),
            );
        }

        $payload = array('fields' => array_values($fields));

        if (array() !== $context) {
            $payload['context'] = $context;
        }

        $url = $this->forms_submit_base() . rawurlencode($this->portal_id) . '/' . rawurlencode(trim($form_id));

        $response = self::normalise_response(wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
        )));

        if ($response['status'] === 200) {
            return array(
                'success' => true,
                'status'  => 200,
                'message' => '',
                'errors'  => array(),
            );
        }

        $message = (string) ($response['body']['message'] ?? '');
        $errors  = $response['body']['errors'] ?? array();

        log_message(sprintf(
            'HubSpot form submission failed (HTTP %d): %s',
            $response['status'],
            $message !== '' ? $message : $response['error']
        ));

        return array(
            'success' => false,
            'status'  => $response['status'],
            'message' => $message !== '' ? $message : $generic,
            'errors'  => is_array($errors) ? $errors : array(),
        );
    }

    /**
     * Looks up a contact record ID from an email address.
     *
     * Uses the CRM v3 object API; the legacy `contacts/v1` profile endpoint this
     * replaced only ever worked with the retired `hapikey` auth mode.
     *
     * @param string $email Contact email address.
     * @return string|null Contact ID, or null when not found.
     */
    public function find_contact_id_by_email(string $email): ?string
    {
        $email = trim($email);

        if ($email === '' || ! is_email($email)) {
            return null;
        }

        $response = $this->request(
            'GET',
            'crm/v3/objects/contacts/' . rawurlencode($email) . '?idProperty=email&properties=email'
        );

        if ($response['status'] !== 200) {
            return null;
        }

        $id = (string) ($response['body']['id'] ?? '');

        return $id !== '' ? $id : null;
    }

    /**
     * Creates a deal, optionally associated with a contact.
     *
     * @param array<string,string> $properties Deal properties (dealname, pipeline, dealstage, ...).
     * @param string|null          $contact_id Contact to associate the deal with.
     * @return string|null The new deal ID, or null on failure.
     */
    public function create_deal(array $properties, ?string $contact_id = null): ?string
    {
        $properties = array_filter($properties, static fn ($value): bool => $value !== '' && $value !== null);

        if (array() === $properties) {
            return null;
        }

        $payload = array('properties' => $properties);

        if ($contact_id !== null && $contact_id !== '') {
            $payload['associations'] = array(
                array(
                    'to'    => array('id' => $contact_id),
                    'types' => array(
                        array(
                            'associationCategory' => 'HUBSPOT_DEFINED',
                            'associationTypeId'   => self::ASSOCIATION_DEAL_TO_CONTACT,
                        ),
                    ),
                ),
            );
        }

        $response = $this->request('POST', 'crm/v3/objects/deals', $payload);

        if (! in_array($response['status'], array(200, 201), true)) {
            log_message(sprintf(
                'HubSpot deal creation failed (HTTP %d): %s',
                $response['status'],
                (string) ($response['body']['message'] ?? $response['error'])
            ));

            return null;
        }

        $id = (string) ($response['body']['id'] ?? '');

        return $id !== '' ? $id : null;
    }

    /**
     * Updates an existing deal.
     *
     * @param string               $deal_id    Deal record ID.
     * @param array<string,string> $properties Properties to patch.
     * @return bool True on success.
     */
    public function update_deal(string $deal_id, array $properties): bool
    {
        if (trim($deal_id) === '' || array() === $properties) {
            return false;
        }

        $response = $this->request(
            'PATCH',
            'crm/v3/objects/deals/' . rawurlencode($deal_id),
            array('properties' => $properties)
        );

        return $response['status'] === 200;
    }

    /**
     * Lists the non-archived forms in the connected HubSpot account.
     *
     * @param int  $limit   Maximum forms to request.
     * @return array{forms:array<int,array{id:string,name:string}>,error:string}
     */
    public function list_forms(): array
    {
        if ($this->token === '') {
            return array(
                'forms' => array(),
                'error' => __('No HubSpot private app token has been configured.', 'klyp-cf7-to-hubspot'),
            );
        }

        $cache_key = $this->cache_key('forms_list');
        $cached    = get_transient($cache_key);

        if (is_array($cached)) {
            return array('forms' => $cached, 'error' => '');
        }

        $forms = array();
        $after = '';

        // HubSpot pages this endpoint. Without following paging.next a portal with
        // more than one page of forms was silently truncated, and because the sort
        // happens after collection the missing ones were arbitrary.
        for ($page = 0; $page < self::MAX_FORM_PAGES; $page++) {
            $path = 'marketing/v3/forms/?limit=' . self::FORMS_PAGE_SIZE;

            if ($after !== '') {
                $path .= '&after=' . rawurlencode($after);
            }

            $response = $this->request('GET', $path);

            if ($response['status'] !== 200) {
                $detail = (string) ($response['body']['message'] ?? $response['error']);

                if ($detail === '') {
                    $detail = sprintf(
                        /* translators: %d: HTTP status code */
                        __('HubSpot returned an unexpected response (HTTP %d).', 'klyp-cf7-to-hubspot'),
                        $response['status']
                    );
                }

                log_message('Form list failed: ' . $detail);

                // Keep whatever earlier pages returned rather than losing the lot.
                if ($forms === array()) {
                    return array('forms' => array(), 'error' => $detail);
                }

                break;
            }

            foreach ($response['body']['results'] ?? array() as $form) {
                $id = (string) ($form['id'] ?? '');

                if ($id === '' || ! empty($form['archived'])) {
                    continue;
                }

                $name = (string) ($form['name'] ?? '');

                $forms[] = array(
                    'id'   => $id,
                    'name' => $name !== '' ? $name : $id,
                );
            }

            $after = (string) ($response['body']['paging']['next']['after'] ?? '');

            if ($after === '') {
                break;
            }
        }

        usort($forms, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        set_transient($cache_key, $forms, self::FORM_CACHE_TTL);

        return array('forms' => $forms, 'error' => '');
    }

    /**
     * Lists the deal pipelines and their stages, for the editor's pickers.
     *
     * Needs the `crm.objects.deals.read` scope. Callers should treat a non-empty
     * `error` as "fall back to free-text IDs" rather than a hard failure.
     *
     * @return array{pipelines:array<int,array{id:string,label:string,stages:array<int,array{id:string,label:string}>}>,error:string}
     */
    public function get_deal_pipelines(): array
    {
        if ($this->token === '') {
            return array('pipelines' => array(), 'error' => __('No HubSpot private app token is configured.', 'klyp-cf7-to-hubspot'));
        }

        $cache_key = $this->cache_key('pipelines');
        $cached    = get_transient($cache_key);

        if (is_array($cached)) {
            return array('pipelines' => $cached, 'error' => '');
        }

        $response = $this->request('GET', 'crm/v3/pipelines/deals');

        if ($response['status'] !== 200) {
            $detail = (string) ($response['body']['message'] ?? $response['error']);

            log_message('Deal pipelines failed (HTTP ' . $response['status'] . '): ' . $detail);

            return array(
                'pipelines' => array(),
                'error'     => $detail !== '' ? $detail : sprintf(
                    /* translators: %d: HTTP status code */
                    __('HubSpot returned an unexpected response (HTTP %d).', 'klyp-cf7-to-hubspot'),
                    $response['status']
                ),
            );
        }

        $pipelines = array();

        foreach ($response['body']['results'] ?? array() as $pipeline) {
            $id = (string) ($pipeline['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $stages = array();

            foreach ($pipeline['stages'] ?? array() as $stage) {
                $stage_id = (string) ($stage['id'] ?? '');

                if ($stage_id === '') {
                    continue;
                }

                $stages[] = array(
                    'id'    => $stage_id,
                    'label' => (string) ($stage['label'] ?? $stage_id),
                    'order' => (int) ($stage['displayOrder'] ?? 0),
                );
            }

            usort($stages, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

            $pipelines[] = array(
                'id'     => $id,
                'label'  => (string) ($pipeline['label'] ?? $id),
                'order'  => (int) ($pipeline['displayOrder'] ?? 0),
                'stages' => array_map(
                    static fn (array $stage): array => array('id' => $stage['id'], 'label' => $stage['label']),
                    $stages
                ),
            );
        }

        usort($pipelines, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $pipelines = array_map(
            static fn (array $pipeline): array => array(
                'id'     => $pipeline['id'],
                'label'  => $pipeline['label'],
                'stages' => $pipeline['stages'],
            ),
            $pipelines
        );

        set_transient($cache_key, $pipelines, self::FORM_CACHE_TTL);

        return array('pipelines' => $pipelines, 'error' => '');
    }

    /**
     * Verifies the stored credentials against HubSpot.
     *
     * Listing forms doubles as the check: it exercises the `forms` scope this
     * plugin cannot work without, and returns the list the settings screen shows.
     *
     * @return array{state:string,message:string,forms:array<int,array{id:string,name:string}>}
     */
    public function test_connection(): array
    {
        $missing = array();

        if ($this->portal_id === '') {
            $missing[] = __('Portal ID', 'klyp-cf7-to-hubspot');
        }

        if ($this->token === '') {
            $missing[] = __('Private App token', 'klyp-cf7-to-hubspot');
        }

        if (array() !== $missing) {
            return array(
                'state'   => 'unconfigured',
                'message' => sprintf(
                    /* translators: %s: comma separated list of missing settings */
                    __('Not configured yet — still needed: %s.', 'klyp-cf7-to-hubspot'),
                    implode(', ', $missing)
                ),
                'forms'   => array(),
            );
        }

        $result = $this->list_forms();

        if ($result['error'] !== '') {
            return array(
                'state'   => 'error',
                'message' => sprintf(
                    /* translators: %s: error message returned by HubSpot */
                    __('HubSpot rejected the request: %s', 'klyp-cf7-to-hubspot'),
                    $result['error']
                ),
                'forms'   => array(),
            );
        }

        $count = count($result['forms']);

        return array(
            'state'   => 'connected',
            'message' => sprintf(
                /* translators: 1: portal ID, 2: number of forms */
                _n(
                    'Connected to portal %1$s — %2$s form available.',
                    'Connected to portal %1$s — %2$s forms available.',
                    $count,
                    'klyp-cf7-to-hubspot'
                ),
                $this->portal_id,
                number_format_i18n($count)
            ),
            'forms'   => $result['forms'],
        );
    }

}
