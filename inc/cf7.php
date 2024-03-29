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
function klypHsCf7CatchSubmission($spam, $form)
{
    if ($spam) {
        return $spam;
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
    $hubspot->apiKeyPrivate = get_option('klyp_cf7tohs_api_key_private');
    $hubspot->portalId      = get_option('klyp_cf7tohs_portal_id');
    $hubspot->hsFormId      = get_post_meta($cf7FormId, '_klyp-cf7-to-hubspot-form-id', true);
    $hubspot->cf7EmailField = get_post_meta($cf7FormId, '_klyp-cf7-to-hubspot-cf7-email-field', true);
    $hubspot->hsEmailField  = get_post_meta($cf7FormId, '_klyp-cf7-to-hubspot-email-field', true);

    // if we don't have hubspot setup
    if (empty($hubspot->hsFormId) && empty($hubspot->hsEmailField)) {
        return $spam;
    }

    // create contact
    $hubspotReturn = $hubspot->createContact();

    // if there's an error
    if (isset($hubspotReturn['success']) && $hubspotReturn['success'] === false) {
        // validate errors
        if ($hubspotReturn['errors']) {
            foreach ($hubspotReturn['errors'] as $key => $value) {
                $hsErrorField = klypCf7HsGetStringBetween($value->message, "fields.", "'");
                $hsErrorKey = array_search($hsErrorField, $hsFormFields);

                if ($hsErrorKey) {
                    // lets return proper message
                    add_filter('wpcf7_ajax_json_echo', function ($response, $result) use ($value) {
                        $response['message'] = $value->message;
                        return $response;
                    }, 10, 2);

                    // stop right here
                    return true;
                }
            }
            // we need to stop sending the form
            return true;
        }
    }

    // if there's redirect
    if (isset($cf7FormRedirect) && ! empty($cf7FormRedirect)) {
        add_filter('wpcf7_ajax_json_echo', function ($response, $result) use ($cf7FormRedirect) {
            $response['formRedirect'] = $cf7FormRedirect;
            return $response;
        }, 10, 2);
    }

    return $spam;
}
add_filter('wpcf7_spam', 'klypHsCf7CatchSubmission', 99, 2);

/**
 * Get string between
 * @param string
 * @param string
 * @param string
 * @return string
 */
function klypCf7HsGetStringBetween($string, $start, $end)
{
    if (strpos($string, $start)) {
        $startCharCount = strpos($string, $start) + strlen($start);
        $firstSubStr = substr($string, $startCharCount, strlen($string));
        $endCharCount = strpos($firstSubStr, $end);
        if ($endCharCount == 0) {
            $endCharCount = strlen($firstSubStr);
        }
        return substr($firstSubStr, 0, $endCharCount);
    } else {
        return '';
    }
}
