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

    public function remoteStatus($response)
    {
        if (is_wp_error($response)) {
            $status = $response->get_error_code();
        } else {
            $status = wp_remote_retrieve_response_code($response);
        }

        return $status;
    }

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

    public function updateDeal($properties)
    {
        if (empty($properties)) {
            return;
        }
     
        $url = $this->basePath . 'deals/v1/deal/' . $this->dealId;
        $response = $this->remotePost($url, 'PUT', $properties, 'application/json');
        
        return $response;
    }

    public function updateContact($vid, $properties)
    {
        if (empty($vid) || empty($properties)) {
            return;
        }
     
        $url = $this->basePath . 'contacts/v1/contact/vid/' . $vid;
        $response = $this->remotePost($url, 'POST', $properties, 'application/json');
        
        return $response;
    }

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
