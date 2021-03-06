<?php

/**
 * High level functionality for interacting with the ecobee API.
 *
 * @author Jon Ziebell
 */
class ecobee extends external_api {

  public static $exposed = [
    'private' => [],
    'public' => [
      'authorize',
      'initialize'
    ]
  ];

  protected static $log_influx = true;
  protected static $log_mysql = 'error';

  protected static $cache = false;
  protected static $cache_for = null;

  /**
   * Redirect to ecobee to do the oAuth.
   */
  public function authorize() {
    header('Location: https://api.ecobee.com/authorize?response_type=code&client_id=' . $this->setting->get('ecobee_client_id') . '&redirect_uri=' . $this->setting->get('ecobee_redirect_uri') . '&scope=smartRead');
    die();
  }

  /**
   * Obtain the first set of tokens for an ecobee user.
   *
   * @param string $code The code used to get tokens from ecobee with.
   * @param string $error Error short description.
   * @param string $error_description Error long description.
   */
  public function initialize($code = null, $error = null, $error_description = null) {
    if($code !== null) {
      // This is returned, not created in the database because the user may not
      // exist yet.
      $ecobee_token = $this->api('ecobee_token', 'obtain', ['code' => $code]);

      // Get the thermostat list from ecobee.
      $response = $this->ecobee_api(
        'GET',
        'thermostat',
        [
          'body' => json_encode([
            'selection' => [
              'selectionType' => 'registered',
              'selectionMatch' => '',
              'includeRuntime' => true,
              'includeNotificationSettings' => true
            ]
          ])
        ],
        false,
        $ecobee_token
      );

      $guids = [];
      $email_addresses = [];
      foreach($response['thermostatList'] as $thermostat) {
        $runtime = $thermostat['runtime'];
        $guid = sha1($thermostat['identifier'] . $runtime['firstConnected']);
        $guids[] = $guid;

        $notification_settings = $thermostat['notificationSettings'];
        $email_addresses = array_merge($email_addresses, $notification_settings['emailAddresses']);
      }

      // Look to see if any of the returned thermostats exist. This does not use
      // CRUD because it needs to bypass the user_id restriction (also I don't
      // think you're logged in yet)
      $existing_ecobee_thermostats = $this->database->read(
        'ecobee_thermostat',
        [
          'guid' => $guids
        ]
      );

      // If at least one of the thermostats from the ecobee API call already
      // exists and all of them have matching user_ids, log in as that user.
      // Otherwise create a new user and save the tokens to it.
      if(
        count($existing_ecobee_thermostats) > 0 &&
        count(array_unique(array_column($existing_ecobee_thermostats, 'user_id'))) === 1
      ) {
        $this->api(
          'user',
          'force_log_in',
          ['user_id' => $existing_ecobee_thermostats[0]['user_id']]
        );

        // Look for existing tokens (in case access was revoked and then re-
        // granted). Include deleted tokens and revive that row since each user
        // is limited to one token row.
        $existing_ecobee_token = $this->api(
          'ecobee_token',
          'read',
          [
            'attributes' => [
              'deleted' => [0, 1]
            ]
          ]
        )[0];

        $this->api(
          'ecobee_token',
          'update',
          [
            'attributes' => array_merge(
              ['ecobee_token_id' => $existing_ecobee_token['ecobee_token_id']],
              $ecobee_token
            )
          ]
        );
      }
      else {
        $this->api('user', 'create_anonymous_user');
        $this->api('ecobee_token', 'create', ['attributes' => $ecobee_token]);

        if(count($email_addresses) > 0) {
          try {
            $this->api(
              'mailchimp',
              'subscribe',
              [
                'email_address' => $email_addresses[0]
              ]
            );
          } catch(Exception $e) {
            // Ignore failed subscribe exceptions since it's not critical to the
            // success of this. Everything is logged regardless.
          }
        }

      }

      // Redirect to the proper location.
      header('Location: ' . $this->setting->get('beestat_root_uri') . 'dashboard/');
    }
    else if(isset($error) === true) {
      throw new Exception($error_description);
    }
    else {
      throw new Exception('Unhandled error');
    }
  }

  /**
   * Send an API call to ecobee and return the response.
   *
   * @param string $method GET or POST
   * @param string $endpoint The API endpoint
   * @param array $arguments POST or GET parameters
   * @param boolean $auto_refresh_token Whether or not to automatically get a
   * new token if the old one is expired.
   * @param string $ecobee_token Force-use a specific token.
   *
   * @return array The response of this API call.
   */
  public function ecobee_api($method, $endpoint, $arguments, $auto_refresh_token = true, $ecobee_token = null) {
    $curl = [
      'method' => $method
    ];

    // Attach the client_id to all requests.
    $arguments['client_id'] = $this->setting->get('ecobee_client_id');

    // Authorize/token endpoints don't use the /1/ in the URL. Everything else
    // does.
    $full_endpoint = $endpoint;
    if ($full_endpoint !== 'authorize' && $full_endpoint !== 'token') {
      $full_endpoint = '/1/' . $full_endpoint;

      // For non-authorization endpoints, add the access_token header. Will use
      // provided token if set, otherwise will get the one for the logged in
      // user.
      if($ecobee_token === null) {
        $ecobee_tokens = $this->api(
          'ecobee_token',
          'read',
          []
        );
        if(count($ecobee_tokens) !== 1) {
          throw new Exception('No token for this user');
        }
        $ecobee_token = $ecobee_tokens[0];
      }

      $curl['header'] = [
        'Authorization: Bearer ' . $ecobee_token['access_token']
      ];
    }
    else {
      $full_endpoint = '/' . $full_endpoint;
    }
    $curl['url'] = 'https://api.ecobee.com' . $full_endpoint;

    if ($method === 'GET') {
      $curl['url'] .= '?' . http_build_query($arguments);
    }

    if ($method === 'POST') {
      $curl['post_fields'] = http_build_query($arguments);
    }

    $curl_response = $this->curl($curl);

    $response = json_decode($curl_response, true);
    if ($response === null) {
      // If this hasn't already been logged, log the error.
      if($this::$log_mysql !== 'all') {
        $this->log_mysql($curl_response);
      }
      throw new Exception('Invalid JSON');
    }

    // If the token was expired, refresh it and try again. Trying again sets
    // auto_refresh_token to false to prevent accidental infinite refreshing if
    // something bad happens.
    if (isset($response['status']) === true && $response['status']['code'] === 14) {
      // Authentication token has expired. Refresh your tokens.
      if ($auto_refresh_token === true) {
        $this->api('ecobee_token', 'refresh');
        return $this->ecobee_api($method, $endpoint, $arguments, false);
      }
      else {
        if($this::$log_mysql !== 'all') {
          $this->log_mysql($curl_response);
        }
        throw new Exception($response['status']['message']);
      }
    }
    else if (isset($response['status']) === true && $response['status']['code'] !== 0) {
      // Any other error
      if($this::$log_mysql !== 'all') {
        $this->log_mysql($curl_response);
      }
      throw new Exception($response['status']['message']);
    }
    else {
      return $response;
    }
  }
}
