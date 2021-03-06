<?php

/**
 * Tokens for authorizing access to Patreon accounts.
 *
 * @author Jon Ziebell
 */
class patreon_token extends cora\crud {

  public static $converged = [];

  public static $user_locked = true;

  /**
   * Obtain Patreon access & refresh tokens. If a token already exists for
   * this user, overwrite it.
   *
   * @param string $code The code from patreon used to obtain the
   * access/refresh tokens.
   *
   * @return array The patreon_token row.
   */
  public function obtain($code) {
    // Obtain the access and refresh tokens from the authorization code.
    $response = $this->api(
      'patreon',
      'patreon_api',
      [
        'method' => 'POST',
        'endpoint' => 'token',
        'arguments' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'redirect_uri' => $this->setting->get('patreon_redirect_uri')
        ]
      ]
    );

    // Make sure we got the expected result.
    if (
      isset($response['access_token']) === false ||
      isset($response['refresh_token']) === false
    ) {
      throw new Exception('Could not get first token');
    }

    $new_patreon_token = [
      'access_token' => $response['access_token'],
      'refresh_token' => $response['refresh_token']
    ];

    $existing_patreon_tokens = $this->read();
    if(count($existing_patreon_tokens) > 0) {
      $new_patreon_token['patreon_token_id'] = $existing_patreon_tokens[0]['patreon_token_id'];
      $this->update(
        $new_patreon_token
      );
    }
    else {
      $this->create($new_patreon_token);
    }

    return $this->read()[0];
  }

  /**
   * Get some new tokens. A database lock is obtained prior to getting a token
   * so that no other API call can attempt to get a token at the same time.
   * This way if two API calls fire off to patreon at the same time, then
   * return at the same time, then call token->refresh() at the same time,
   * only one can run and actually refresh at a time. If the second one runs
   * after that's fine as it will look up the token prior to refreshing.
   *
   * Also this creates a new database connection. If a token is written to the
   * database, then the transaction gets rolled back, the token will be
   * erased. I originally tried to avoid this by not using transactions except
   * when syncing, but there are enough sync errors that happen where this
   * causes a problem. The extra overhead of a second database connection
   * every now and then shouldn't be that bad.
   */
  public function refresh() {
    $database = cora\database::get_second_instance();

    $lock_name = 'patreon_token->refresh(' . $this->session->get_user_id() . ')';
    $database->get_lock($lock_name, 3);

    // $patreon_tokens = $this->read();
    $patreon_tokens = $database->read(
      'patreon_token',
      [
        'user_id' => $this->session->get_user_id()
      ]
    );
    if(count($patreon_tokens) === 0) {
      throw new Exception('Could not refresh patreon token; no token found.', 10002);
    }
    $patreon_token = $patreon_tokens[0];

    $response = $this->api(
      'patreon',
      'patreon_api',
      [
        'method' => 'POST',
        'endpoint' => 'token',
        'arguments' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $patreon_token['refresh_token']
        ]
      ]
    );

    if (
      isset($response['access_token']) === false ||
      isset($response['refresh_token']) === false
    ) {
      $this->delete($patreon_token['patreon_token_id']);
      $database->release_lock($lock_name);
      throw new Exception('Could not refresh patreon token; patreon returned no token.', 10003);
    }

    $database->update(
      'patreon_token',
      [
        'patreon_token_id' => $patreon_token['patreon_token_id'],
        'access_token' => $response['access_token'],
        'refresh_token' => $response['refresh_token'],
        'timestamp' => date('Y-m-d H:i:s')
      ]
    );

    $database->release_lock($lock_name);
  }

  /**
   * Delete an patreon token.
   *
   * @param int $id
   *
   * @return int
   */
  public function delete($id) {
    $database = database::get_second_instance();
    $return = $database->delete('patreon_token', $id);
    return $return;
  }

}
