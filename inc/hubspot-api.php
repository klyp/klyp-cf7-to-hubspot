<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

// php8 backwards compatability based on original work from the PHP Laravel framework 
if (! function_exists('str_contains')) {
    /**
     * A str_contrains function for backwards compatability
     * 
     * @param String $haystack The String to be searched
     * @param String $needle The String for search for
     * 
     * @return bool Returns if the $haystack contains the needle
     */
    function str_contains($haystack, $needle) 
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

class klypHubspot
{
    private $dealbreaker = false;
    public $apiKey;
    public $apiKeyPrivate;
    public $portalId;
    public $basePath;

    public $cf7FormId;
    public $cf7FormFields;
    public $hsFormFields;

    public $dealId;
    public $cf7EmailField;
    public $hsEmailField;
    public $hsFormId;
    public $postedData = array();

    public function __construct()
    {
        $this->apiKey           = get_option('klyp_cf7tohs_api_key');
        $this->apiKeyPrivate    = get_option('klyp_cf7tohs_api_key_private');
        $this->keyMode          = $this->apiKeyPrivate != '' ? 'private' : 'apikey';
        $this->portalId         = get_option('klyp_cf7tohs_portal_id');
        $this->basePath         = get_option('klyp_cf7tohs_base_url');
    }

    /**
     * Make a POST request
     * 
     * @param string $url The url to post to
     * @param string $method The method to use (default POST)
     * @param mixed $body The body of data to be posted
     * @param string $contentType The specifed content type to be sent
     * 
     * @return array Returns an array of the response
     */
    private function remotePost($url, $method = 'POST', $body, $contentType)
    {
        $headers = array(
            'Content-Type' => $contentType
        );
        if ($this->keyMode == 'apikey') {
            if (str_contains($url, '?')) {
                $url = $url . '&hapikey=' . $this->apiKey;
            } else {
                $url = $url . '?hapikey=' . $this->apiKey;
            }
        } else if ($this->keyMode == 'private') {
            $headers['authorization'] = 'Bearer ' . $this->apiKeyPrivate;
        }

        $response = wp_remote_post(
            $url,
            array(
                'method'  => $method,
                'body'    => wp_json_encode($body),
                'headers' => $headers
            )
        );

        return $response;
    }

    /**
     * Make a GET request
     * 
     * @param string $url The url to post to
     * @param string $contentType The specifed content type to be sent
     * 
     * @return array Returns an array of the response
     */
    private function remoteGet($url, $contentType)
    {
        $headers = array(
            'Content-Type' => $contentType
        );
        if ($this->keyMode == 'apikey') {
            if (str_contains($url, '?')) {
                $url = $url . '&hapikey=' . $this->apiKey;
            } else {
                $url = $url . '?hapikey=' . $this->apiKey;
            }
        } else if ($this->keyMode == 'private') {
            $headers['authorization'] = 'Bearer ' . $this->apiKeyPrivate;
        }

        $response = wp_remote_get(
            $url,
            array(
                'headers' => $headers
            )
        );

        return $response;
    }

    /**
     * Parse the status from the response
     * 
     * @param mixed $response The response needing to be parsed
     * 
     * @return string The status of the reponse
     */
    public function remoteStatus($response)
    {
        if (is_wp_error($response)) {
            $status = $response->get_error_code();
        } else {
            $status = wp_remote_retrieve_response_code($response);
        }

        return $status;
    }

    /**
     * Process the data of a submitted form and turn it into a format that HubSpot expects
     * 
     * @return array An array of user data
     */
    private function processData()
    {
        for ($i = 0; $i <= count($this->cf7FormFields); $i++) {
            if ($this->cf7FormFields[$i] != '') {
                $this->data[] = array(
                    'name'  => $this->hsFormFields[$i],
                    'value' => (is_array($this->postedData[$this->cf7FormFields[$i]]) ? implode(';', $this->postedData[$this->cf7FormFields[$i]]) : sanitize_text_field($this->postedData[$this->cf7FormFields[$i]]))
                );
            }
        }
        return $this->data;
    }

    /**
     * Packages the metadata for the current user session ready to be submitted to HubSpot
     * 
     * @return array An array containing metadata about the user session
     */
    private function processContextData()
    {
        $hutk = isset($_COOKIE['hubspotutk']) ? sanitize_text_field($_COOKIE['hubspotutk']) : '';
        $referrer = wp_get_referer();
        $objId = 0;

        if ($referrer) {
            $objId = url_to_postid($referrer);
        }

        $currentUrl = get_permalink($objId);
        $pageName = get_the_title($objId);
        $context = array();

        if (! empty($hutk)) {
            $context['hutk'] = $hutk;
        }

        if (! empty($currentUrl)) {
            $context['pageUri'] = $currentUrl;
        }

        if (! empty($pageName)) {
            $context['pageName'] = $pageName;
        }

        $context = array(
            'context' => $context
        );

        return $context;
    }

    /**
     * Checks to see if a deal needs to be made from the form submission
     * 
     * @return array a bool condition and a message on whether to create a deal
     */
    public function processDealbreaker()
    {
        $return = array(
                'success'   => true,
                'message'   => '',
            );

        // get settings
        $hsDealbreakerAllow  = (bool) get_post_meta($this->cf7FormId, '_klyp-cf7-to-hubspot-dealbreaker-allow', true);
        $hsDealbreakerFields = get_post_meta($this->cf7FormId, '_klyp-cf7-to-hubspot-dealbreaker-field', true);
        $hsDealbreakerValues = get_post_meta($this->cf7FormId, '_klyp-cf7-to-hubspot-dealbreaker-value', true);

        // if do not create deals is set
        if ($hsDealbreakerAllow === true) {
            $return = array(
                'success'   => false,
                'message'   => 'Do not create deals is set'
            );
        }

        for ($i = 0; $i <= count($hsDealbreakerFields); $i++) { 
            // if a condition is met
            if (sanitize_text_field($this->postedData[$hsDealbreakerFields[$i]]) == $hsDealbreakerValues[$i] && sanitize_text_field($this->postedData[$hsDealbreakerFields[$i]]) != '') {
                $this->dealbreaker = true;
                break;
            }
        }

        if ($this->dealbreaker == true) {
            $return = array(
                'success'   => false,
                'message'   => 'A deal breaker condition is met'
            );
        }

        return $return;
    }

    /**
     * Get a contact property from an email
     * 
     * @param string $email The email of the user
     * @param string $property The Property being searched
     * 
     * @return string The property value
     */
    public function getContactPropertyByEmail($email, $property)
    {
        if (! $email || ! $property) {
            return;
        }

        $url        = $this->basePath . 'contacts/v1/contact/email/' . $email . '/profile';
        $response   = $this->remoteGet($url, 'application/json');
        $status     = $this->remoteStatus($response);

        if ($status == 200) {
            $body = wp_remote_retrieve_body($response);

            if ($body) {
                $body = json_decode($body);
            }

            if ($property == 'vid') {
                $return = $body->vid;
            } else {
                $return = $body->properties->{$property}->value;
            }
        }

        return $return;
    }

    /**
     * Get a contact property from an id
     * 
     * @param string $contactId The id of the user
     * 
     * @return string The user details
     */
    public function getContactPropertyById($contactId)
    {
        if (! $contactId) {
            return;
        }

        $url        = $this->basePath . 'contacts/v1/contact/vid/' . $contactId . '/profile';
        $response   = $this->remoteGet($url, 'application/json');
        $status     = $this->remoteStatus($response);

        if ($status == 200) {
            $body = wp_remote_retrieve_body($response);
            return $body;
        }

        return;
    }

    /**
     * Get the details of a deal from the deal id
     * 
     * @param string $dealId The id of the deal
     * 
     * @return mixed array|object The deal in an array format
     */
    public function getDealDetails($dealId)
    {
        if (! $dealId) {
            return;
        }

        $url        = $this->basePath . 'deals/v1/deal/' . $dealId;
        $response   = $this->remoteGet($url, 'application/json');
        $status     = $this->remoteStatus($response);

        if ($status == 200) {
            $body = wp_remote_retrieve_body($response);
            return $body;
        }

        return;
    }

    /**
     * Get the fields of the of a form, with an option to specify a property
     * 
     * @param string $formId The Id of the HubSpot form
     * @param string $property The specfied property to get the field value of
     * 
     * @return mixed array|string Returns an array of fields or the value (type) of just one
     */
    public function getFormFields($formId, $property = null)
    {
        $url        = $this->basePath . 'marketing/v3/forms/' . $formId;
        $response   = $this->remoteGet($url, 'application/json');
        $status     = $this->remoteStatus($response);

        if ($status == 200) {
            $body = wp_remote_retrieve_body($response);

            if ($body) {
                $body = json_decode($body);
            }

            $activeFields = [];
            foreach ($body->fieldGroups as $key => $fieldGroups) {
                foreach ($fieldGroups->fields as $key => $field) {
                    array_push($activeFields, $field);
                }
            }

            if ($property) {
                foreach ($activeFields as $key => $value) {
                    if ($value->name == $property) {
                        return $value->type;
                    }
                }
            } else {
                return $activeFields;
            }
        }

        exit();
    }

    /**
     * Update a deal with the given properties
     * 
     * @param array $properties An array of properties to update the deal with
     * 
     * @return array The array message showing the success of the request
     */
    public function updateDeal($properties)
    {
        if (empty($properties)) {
            return;
        }
     
        $url = $this->basePath . 'deals/v1/deal/' . $this->dealId;
        $response = $this->remotePost($url, 'PUT', $properties, 'application/json');
        
        return $response;
    }

    /**
     * Update a Contact with the given properties
     * 
     * @param string $vid The id of the user in HubSpot
     * @param array $properties An array of properties to update the Contact with
     * 
     * @return array The array message showing the success of the request
     */
    public function updateContact($vid, $properties)
    {
        if (empty($vid) || empty($properties)) {
            return;
        }
     
        $url = $this->basePath . 'contacts/v1/contact/vid/' . $vid;
        $response = $this->remotePost($url, 'POST', $properties, 'application/json');
        
        return $response;
    }

    /**
     * Create a Contact using the field values from the form and create a deal if needed
     * 
     * @return array The array message showing the success of the request
     */
    public function createContact()
    {
        $data       = array('fields' => $this->processData());
        $context    = $this->processContextData();
        $url        = 'https://api.hsforms.com/submissions/v3/integration/submit/' . $this->portalId . '/' . $this->hsFormId;

        if (! empty($context['context'])) {
            $data = array_merge($data, $context);
        }

        $response   = $this->remotePost($url, 'POST', $data, 'application/json');
        $status     = $this->remoteStatus($response);

        if ($status == 200) {

            $dealbreaker = $this->processDealbreaker();

            // if deal breaker then return
            if ((isset($dealbreaker['success']) && $dealbreaker['success'] === false)) {
                return $dealbreaker;
            }

            // use email field to create deal
            if (! empty ($this->cf7EmailField) && ! empty($this->hsEmailField)) {
                $cf7EmailField  = sanitize_text_field($this->postedData[$this->cf7EmailField]);
                $hsEmail        = sanitize_text_field($this->postedData[$this->hsEmailField]);

                $hsContactId    = $this->getContactPropertyByEmail($cf7EmailField, 'vid');
                $stageId        = esc_html(get_post_meta($this->cf7FormId, '_klyp-cf7-to-hubspot-stage-id', true));
                $pipelineId     = esc_html(get_post_meta($this->cf7FormId, '_klyp-cf7-to-hubspot-pipeline-id', true));

                // if we get contact id
                if ($hsContactId) {
                    $deal = array(
                        'properties'   => array(
                            array(
                                'name'  => 'dealstage',
                                'value' => $stageId
                            ),
                            array(
                                'name'  => 'pipeline',
                                'value' => $pipelineId
                            ),
                            array(
                                'name' => 'dealname',
                                'value' => $cf7EmailField
                            )
                        ),
                        'associations' => array(
                            'associatedVids' => array($hsContactId)
                        ),
                    );

                    // create new deal
                    $this->dealId = $this->createDeal($deal);
                    
                    if (! empty($this->dealId)) {
                        $properties = array();

                        $properties[] = array(
                            'name' => 'dealname',
                            'value' => $cf7EmailField . ' - ' . $this->dealId
                        );

                        $properties = array('properties' => $properties);

                        // update deal
                        $response = $this->updateDeal($properties);
                    }
                }
            }

            $return = array(
                'success'   => true,
                'message'   => '',
                'dealId'    => $this->dealId
            );

        } else {

            $response = wp_remote_retrieve_body($response);

            if ($response) {
                $message = json_decode($response)->message ?: 'There is something wrong while processing your request. Please try again later.';
                $errors = json_decode($response)->errors;
            } else {
                $message = 'There is something wrong while processing your request. Please try again later.';
                $errors = null;
            }

            $return = array(
                'success'   => false,
                'message'   => $message,
                'errors'    => $errors
            );
        }

        return $return;
    }

    /**
     * Create a deal using an array of properties
     * 
     * @param array $deal An array of properties to post to HubSpot
     * 
     * @return $string The Id of the deal
     */
    private function createDeal($deal)
    {
        if (empty ($deal)) {
            return;
        }

        $id         = '';
        $url        = $this->basePath . 'deals/v1/deal';
        $response   = $this->remotePost($url, 'POST', $deal, 'application/json');
        $status     = $this->remoteStatus($response);        

        if ($status === 200) {
            $body = wp_remote_retrieve_body($response);

            if ($body) {
                $body = json_decode($body);
            }

            $id = $body->dealId;
        }

        return $id;
    }
}
