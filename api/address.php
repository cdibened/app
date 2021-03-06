<?php

/**
 * An address is a discrete object that is normalized and verified using a
 * third party service. In order to prevent duplication and extra API calls
 * (which cost money), they are stored separately instead of simply as columns
 * on a different table.
 *
 * @author Jon Ziebell
 */
class address extends cora\crud {

  public static $exposed = [
    'private' => [
      'read_id'
    ],
    'public' => []
  ];

  public static $converged = [
    'normalized' => [
      'type' => 'json'
    ]
  ];

  public static $user_locked = true;

  /**
   * Search for an address based on an address string. This will make an API
   * call to Smarty Streets using that address string (after first checking
   * the cache to see if we've done it before), then it will either create the
   * address row for this user or return the existing one if it already
   * exists.
   *
   * For example:
   *
   * 1. 123 Sesame St. (query smarty, insert row)
   * 2. 123 Sesame Street (query smarty, return existing row)
   * 3. 123 Sesame Street (query smarty (cached), return existing row)
   *
   * @param string $address_string Freeform address string
   * @param string $country ISO 3 country code
   *
   * @return array The address row.
   */
  public function search($address_string, $country) {
    $normalized = $this->api(
      'smarty_streets',
      'smarty_streets_api',
      [
        'street' => $address_string,
        'country' => $country
      ]
    );

    $key = $this->generate_key($normalized);
    $existing_address = $this->get([
      'key' => $key
    ]);

    if($existing_address === null) {
      return $this->create([
        'key' => $key,
        'normalized' => $normalized
      ]);
    }
    else {
      return $existing_address;
    }
  }

  /**
   * Generate a key from the normalized address to see whether or not it's
   * been stored before. Note that SmartyStreets does not recommend using the
   * DPBC as a unique identifier. I am here, but the key is not intended to be
   * a unique identifier for an address. It's meant to be a representation of
   * the full details of an address. If the ZIP code changes for someone's
   * house, I need to store that as a new address or the actual address will
   * be incorrect.
   *
   * @link https://smartystreets.com/docs/addresses-have-unique-identifier
   *
   * @param string $normalized Normalized address as returned from
   * SmartyStreets
   *
   * @return string
   */
  private function generate_key($normalized) {
    if(isset($normalized['delivery_point_barcode']) === true) {
      return sha1($normalized['delivery_point_barcode']);
    } else {
      $string = '';
      if(isset($normalized['address1']) === true) {
        $string .= $normalized['address1'];
      }
      if(isset($normalized['address2']) === true) {
        $string .= $normalized['address2'];
      }
      if(isset($normalized['address3']) === true) {
        $string .= $normalized['address3'];
      }
      return sha1($string);
    }
  }

}
