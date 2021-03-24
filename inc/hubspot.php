<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

/**
 * Add custom hubspot settings for form 7
 * @param array
 * @return array
 */

function klypCf7HsAdditionalSettings($panels)
{
    $panels['klyp-hs-settings-panel'] = array (
        'title' => 'Hubspot Integration',
        'callback' => 'klypCf7HsAdditionalSettingsTab'
    );

    return $panels;
}
add_filter('wpcf7_editor_panels', 'klypCf7HsAdditionalSettings');

/**
 * Add custom hubspot settings for form 7
 * @param object
 * @return string
 */
function klypCf7HsAdditionalSettingsTab($post)
{
    echo '
        <h2>Hubspot Settings</h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <td>
                        <p class="description">
                            Hubspot Form ID
                        </p>
                        <label for="klyp-cf7-to-hubspot-form-id">
                            <input type="text" id="klyp-cf7-to-hubspot-form-id" name="klyp-cf7-to-hubspot-form-id" value="' . esc_html(get_post_meta($post->id(), '_klyp-cf7-to-hubspot-form-id', true)) . '" class="large-text code">
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>';

    if (get_post_meta($post->id(), '_klyp-cf7-to-hubspot-form-id', true) != '') {
        $cfFields       = klypCf7HsGetCfFormFields($post->id());
        $klypHubspot    = new klypHubspot();
        $hsFields       = $klypHubspot->getFormFields(get_post_meta($post->id(), '_klyp-cf7-to-hubspot-form-id', true));

        echo '
        <h2>Form Settings</h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <td>
                        <p class="description">
                            Redirect after form submission
                        </p>
                        <label for="klyp-cf7-to-hubspot-form-redirect">
                            <input type="text" id="klyp-cf7-to-hubspot-form-redirect" name="klyp-cf7-to-hubspot-form-redirect" value="' . esc_html(get_post_meta($post->id(), '_klyp-cf7-to-hubspot-form-redirect', true)) . '" class="large-text code">
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>

        <p><hr></p>

        <h2>Hubspot Integrations</h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <td width="50%">
                        <p class="description">
                            Email field name used in CF7 form
                        </p>
                        <label for="klyp-cf7-to-hubspot-cf7-email-field">
                            <select id="klyp-cf7-to-hubspot-cf7-email-field" name="klyp-cf7-to-hubspot-cf7-email-field" class="large-text code">
                                <option value="">Please select form field to map</option>';

                                foreach ($cfFields as $key => $cfField) {
                                    if ($cfField->name != '') {
                                        echo '<option value="' . $cfField->name . '" ' . (esc_html(get_post_meta($post->id(), '_klyp-cf7-to-hubspot-cf7-email-field', true)) == $cfField->name ? 'selected="selected"' : '') . '>' . $cfField->name . '</option>';
                                    }
                                }
                echo '
                            </select>
                        </label>
                    </td>
                    <td width="50%">
                        <p class="description">
                            Email field name used in hubspot form
                        </p>
                        <label for="klyp-cf7-to-hubspot-email-field">
                            <select id="klyp-cf7-to-hubspot-email-field" name="klyp-cf7-to-hubspot-email-field" class="large-text code">
                                <option value="">Please select email field</option>';

                                foreach ($hsFields as $key => $hsField) {
                                    echo '<option value="' . $hsField->name . '"' . ((get_post_meta($post->id(), '_klyp-cf7-to-hubspot-email-field', true) == $hsField->name) ? 'selected="selected"' : '') . '>' . $hsField->label . ' (' . $hsField->name . ')</option>';
                                }
                echo '
                            </select>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <p class="description">
                            Hubspot Pipeline ID
                        </p>
                        <label for="klyp-cf7-to-hubspot-pipeline-id">
                            <input type="text" id="klyp-cf7-to-hubspot-pipeline-id" name="klyp-cf7-to-hubspot-pipeline-id" value="' . esc_html(get_post_meta($post->id(), '_klyp-cf7-to-hubspot-pipeline-id', true)) . '" class="large-text code">
                        </label>
                    </td>
                    <td>
                        <p class="description">
                            Hubspot Stage ID
                        </p>
                        <label for="klyp-cf7-to-hubspot-stage-id">
                            <input type="text" id="klyp-cf7-to-hubspot-stage-id" name="klyp-cf7-to-hubspot-stage-id" value="' . esc_html(get_post_meta($post->id(), '_klyp-cf7-to-hubspot-stage-id', true)) . '" class="large-text code">
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>

        <p><hr></p>

        <h2>Form Mapping</h2>
        <p>Map contact form fields to Hubspot fields</p>
        <table class="form-table" role="presentation">
            <thead>
                <tr>
                    <th width="45%">
                        Contact Form Field
                    </th>
                    <th width="45%">
                        Hubspot Form Field
                    </th>
                    <td width="10%" align="right">
                        <button id="klyp-cf7-to-hubspot-map-add-new-map">+</button>
                    </td>
                </tr>
            </thead>
            <tbody id="klyp-cf7-to-hubspot-tbody-map">';
                $cfMapFormFields = get_post_meta($post->id(), '_klyp-cf7-to-hubspot-cf-map-fields', true);
                $hsMapFormFields = get_post_meta($post->id(), '_klyp-cf7-to-hubspot-hs-map-fields', true);

                if (! empty($cfMapFormFields)) {
                    for ($i = 0; $i <= count($cfMapFormFields); $i++) {

                        if (empty($cfMapFormFields[$i]) || empty($hsMapFormFields[$i])) {
                            continue;
                        }

                        echo '
                            <tr>
                                <td>
                                    <select id="klyp-cf7-to-hubspot-cf-map-fields" name="klyp-cf7-to-hubspot-cf-map-fields[]" class="large-text code">
                                        <option value="">Please select form field to map</option>';

                                        foreach ($cfFields as $key => $cfField) {
                                            if ($cfField->name != '') {
                                                echo '<option value="' . $cfField->name . '" ' . ($cfMapFormFields[$i] == $cfField->name ? 'selected="selected"' : '') . '>' . $cfField->name . '</option>';
                                            }
                                        }
                        echo '
                                    </select>
                                </td>
                                <td>
                                    <select id="klyp-cf7-to-hubspot-hs-map-fields" name="klyp-cf7-to-hubspot-hs-map-fields[]" class="large-text code">
                                        <option value="">Please select hubspot field to map</option>';

                                        foreach ($hsFields as $key => $hsField) {
                                            if ($hsField->name != '') {
                                                echo '<option value="' . $hsField->name . '" ' . ($hsMapFormFields[$i] == $hsField->name ? 'selected="selected"' : '') . '>' . $hsField->label . ' (' . $hsField->name . ')</option>';
                                            }
                                        }
                        echo '
                                </td>
                                <td align="right">
                                    <button class="klyp-cf7-to-hubspot-cf-remove-map">x</button>
                                </td>
                            </tr>';
                    }
                }
        echo '
            </tbody>
            <tfoot id="klyp-cf7-to-hubspot-tfoot-map" style="display:none;">
                <tr>
                    <td>
                        <select id="klyp-cf7-to-hubspot-cf-map-fields" name="klyp-cf7-to-hubspot-cf-map-fields[]" class="large-text code">
                                <option value="">Please select form field to map</option>';

                            foreach ($cfFields as $key => $cfField) {
                                if ($cfField->name != '') {
                                    echo '<option value="' . $cfField->name . '">' . $cfField->name . '</option>';
                                }
                            }
        echo '
                        </select>
                    </td>
                    <td>
                        <select id="klyp-cf7-to-hubspot-hs-map-fields" name="klyp-cf7-to-hubspot-hs-map-fields[]" class="large-text code">
                            <option value="">Please select hubspot field to map</option>';

                            foreach ($hsFields as $key => $hsField) {
                                if ($hsField->name != '') {
                                    echo '<option value="' . $hsField->name . '">' . $hsField->label . ' (' . $hsField->name . ')</option>';
                                }
                            }
        echo '          </select>
                    </td>
                    <td align="right">
                        <button class="klyp-cf7-to-hubspot-cf-remove-map">x</button>
                    </td>
                </tr>
            </tfoot>
        </table>

        <p><hr></p>';

            $hsDealbreakerAllow  = (bool) get_post_meta($post->id(), '_klyp-cf7-to-hubspot-dealbreaker-allow', true);
            $hsDealbreakerFields = get_post_meta($post->id(), '_klyp-cf7-to-hubspot-dealbreaker-field', true);
            $hsDealbreakerValues = get_post_meta($post->id(), '_klyp-cf7-to-hubspot-dealbreaker-value', true);

        echo '

        <h2>Deal Creation Conditions</h2>
        <p>
            <label for="klyp-cf7-to-hubspot-dealbreaker-allow">
                <input type="checkbox" id="klyp-cf7-to-hubspot-dealbreaker-allow" name="klyp-cf7-to-hubspot-dealbreaker-allow" value="true" ' . (($hsDealbreakerAllow === true) ? 'checked="checked"' : '') . '> Do not create deals
            </label>
        </p>
        
        <p><hr></p>';

        echo '
        <div id="klyp-cf7-to-hubspot-dealbreakers" style="' . ($hsDealbreakerAllow === true ? 'display:none;' : '') . '">
            <p>Do not create deal if any of the following condtion is met</p>
            <table class="form-table" role="presentation">
                <thead>
                    <tr>
                        <th width="45%">
                            If Field
                        </th>
                        <th width="45%">
                            Is
                        </th>
                        <td width="10%" align="right">
                            <button id="klyp-cf7-to-hubspot-map-add-new-dealbreaker">+</button>
                        </td>
                    </tr>
                </thead>
                <tbody id="klyp-cf7-to-hubspot-tbody-dealbreaker">';

                    if (! empty($hsDealbreakerFields)) {
                        for ($i = 0; $i <= count($hsDealbreakerFields); $i++) {

                            if (empty($hsDealbreakerFields[$i]) || empty($hsDealbreakerValues[$i])) {
                                continue;
                            }
                            echo '
                            <tr>
                                <td>
                                    <select id="klyp-cf7-to-hubspot-dealbreaker-field" name="klyp-cf7-to-hubspot-dealbreaker-field[]" class="large-text code">
                                            <option value="">Please select form field to map</option>';

                                            foreach ($cfFields as $key => $cfField) {
                                                if ($cfField->name != '') {
                                                    echo '<option value="' . $cfField->name . '" ' . ($hsDealbreakerFields[$i] == $cfField->name ? 'selected="selected"' : '') . '>' . $cfField->name . '</option>';
                                                }
                                            }
                            echo '
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="klyp-cf7-to-hubspot-dealbreaker-value[]" value="' . $hsDealbreakerValues[$i] . '" class="large-text code">
                                </td>
                                <td align="right">
                                    <button class="klyp-cf7-to-hubspot-cf-remove-dealbreaker">x</button>
                                </td>
                            </tr>';
                        }
                    }
            echo '
                </tbody>
                <tfoot id="klyp-cf7-to-hubspot-tfoot-dealbreaker" style="display:none;">
                    <tr>
                        <td>
                            <select id="klyp-cf7-to-hubspot-dealbreaker-field" name="klyp-cf7-to-hubspot-dealbreaker-field[]" class="large-text code">
                                <option value="">Please select form field to map</option>';

                                foreach ($cfFields as $key => $cfField) {
                                    if ($cfField->name != '') {
                                        echo '<option value="' . $cfField->name . '">' . $cfField->name . '</option>';
                                    }
                                }
                            echo '
                            </select>
                        </td>
                        <td>
                            <input type="text" name="klyp-cf7-to-hubspot-dealbreaker-value[]" value="" class="large-text code">
                        </td>
                        <td align="right">
                            <button class="klyp-cf7-to-hubspot-cf-remove-dealbreaker">x</button>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>';
    }
}

/**
 * On save settings
 * @param object
 * @param array
 * @return void
 */
function klypCf7HsSaveContactForm($contact_form, $args)
{
    // allowed fields
    $cs7Fields = array (
        'klyp-cf7-to-hubspot-cf7-email-field',
        'klyp-cf7-to-hubspot-email-field',
        'klyp-cf7-to-hubspot-form-id',
        'klyp-cf7-to-hubspot-form-redirect',
        'klyp-cf7-to-hubspot-pipeline-id',
        'klyp-cf7-to-hubspot-stage-id',
        'klyp-cf7-to-hubspot-cf-map-fields',
        'klyp-cf7-to-hubspot-hs-map-fields',
        'klyp-cf7-to-hubspot-dealbreaker-allow',
        'klyp-cf7-to-hubspot-dealbreaker-field',
        'klyp-cf7-to-hubspot-dealbreaker-value'
    );

    klypCf7HsSaveSettings($args['id'], $cs7Fields);
}
add_action('wpcf7_save_contact_form', 'klypCf7HsSaveContactForm', 10 ,2);

/**
 * Save CF7 settings
 * @param int
 * @param array
 */
function klypCf7HsSaveSettings($contact_form, $cs7Fields)
{
    foreach ($cs7Fields as $key) {
        if (isset($_POST[$key]) && ! is_array($_POST[$key]) && $_POST[$key] == '') {
            delete_post_meta($contact_form, '_' . $key);
        } elseif (isset($_POST[$key]) && $_POST[$key] != null) {
            $sanitizedValue = klypCF7ToHubspotSanitizeInput($_POST[$key]);
            update_post_meta($contact_form, '_' . $key, $sanitizedValue);
        }
    }

    // checkboxes
    if (! isset($_POST['klyp-cf7-to-hubspot-dealbreaker-allow'])) {
        delete_post_meta($contact_form, '_klyp-cf7-to-hubspot-dealbreaker-allow');
    }
}

/**
 * Get CF7 form fields
 * @param int
 * @return obj
 */
function klypCf7HsGetCfFormFields($formId)
{
    $cf7Form = WPCF7_ContactForm::get_instance($formId);
    return $cf7Form->scan_form_tags();
}
