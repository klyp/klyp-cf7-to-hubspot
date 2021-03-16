<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

/**
 * Add custom redirect page on contact form 7
 * @return void
 */
function klypCF7RedirectOnMailsent()
{
    echo '
    <script type="text/javascript">
        document.addEventListener(\'wpcf7mailsent\', function(event) {
            var details     = event.detail,
                formId      = details.contactFormId,
                response    = details.apiResponse,
                redirect    = response.formRedirect;

            if (typeof redirect !== "undefined") {
                location = redirect;
                return;
            }
        }, false);
    </script>';
}
add_action('wp_footer','klypCF7RedirectOnMailsent');

/**
 * Catch contact form 7 submission
 * @param array
 * @param array
 * @return array
 */
function klypHsCf7CatchSubmission($result, $tags)
{
    if (! $result->is_valid()) {
        return $result;
    }

    // form options
    $cf7FormId          = intval(sanitize_key($_POST['_wpcf7']));
    $cf7FormRedirect    = get_post_meta($cf7FormId, '_klyp-cf7-to-hubspot-form-redirect', true);
    $cf7FormFields      = get_post_meta($cf7FormId, '_klyp-cf7-to-hubspot-cf-map-fields', true);
    $hsFormFields       = get_post_meta($cf7FormId, '_klyp-cf7-to-hubspot-hs-map-fields', true);

    // start hubspot
    $hubspot = new klypHubspot();
    $hubspot->cf7FormId     = $cf7FormId;
    $hubspot->cf7FormFields = $cf7FormFields;
    $hubspot->hsFormFields  = $hsFormFields;
    $hubspot->postedData    = klypCF7ToHubspotSanitizeInput($_POST);
    $hubspot->apiKey        = get_option('klyp_cf7tohs_api_key');
    $hubspot->portalId      = get_option('klyp_cf7tohs_portal_id');
    $hubspot->hsFormId      = get_post_meta($cf7FormId, '_klyp-cf7-to-hubspot-form-id', true);
    $hubspot->cf7EmailField = get_post_meta($cf7FormId, '_klyp-cf7-to-hubspot-cf7-email-field', true);
    $hubspot->hsEmailField  = get_post_meta($cf7FormId, '_klyp-cf7-to-hubspot-email-field', true);

    if (empty($hubspot->hsFormId) && empty($hubspot->hsEmailField)) {
        return $result;
    }

    // create contact
    $hubspotReturn = $hubspot->createContact();

    if (isset($hubspotReturn['success']) && $hubspotReturn['success'] === false) {
        // validate errors
        if ($hubspotReturn['errors']) {
            foreach ($hubspotReturn['errors'] as $key => $value) {
                foreach ($tags as $tagkey => $tag) {
                    if (! empty($tag['name']) && strpos($value->message, 'fields.' . $tag['name']) !== false) {
                        $result->invalidate($tag['name'], $value->message);
                    }
                }
            }
            return $result;
        }
    }

    // if there's redirect
    if (isset($cf7FormRedirect) && ! empty($cf7FormRedirect)) {
        add_filter('wpcf7_ajax_json_echo', function ($response, $result) use ($cf7FormRedirect) {
            $response['formRedirect'] = $cf7FormRedirect;
            return $response;
        }, 10, 2);
    }

    return $result;
}
add_filter('wpcf7_validate', 'klypHsCf7CatchSubmission', 10, 2);
