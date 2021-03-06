<?php

/**
 * An ecobee sensor. This just has a few simple properties like name,
 * temperature, humidity, etc.
 *
 * @author Jon Ziebell
 */
class ecobee_sensor extends cora\crud {

  public static $exposed = [
    'private' => [
      'read_id'
    ],
    'public' => []
  ];

  public static $converged = [];

  public static $user_locked = true;

  /**
   * Sync sensors.
   */
  public function sync() {
    // Get the thermostat list from ecobee with sensors. Keep this identical to
    // ecobee_thermostat->sync() to leverage caching.
    $response = $this->api(
      'ecobee',
      'ecobee_api',
      [
        'method' => 'GET',
        'endpoint' => 'thermostat',
        'arguments' => [
          'body' => json_encode([
            'selection' => [
              'selectionType' => 'registered',
              'selectionMatch' => '',
              'includeRuntime' => true,
              'includeExtendedRuntime' => true,
              'includeElectricity' => true,
              'includeSettings' => true,
              'includeLocation' => true,
              'includeProgram' => true,
              'includeEvents' => true,
              'includeDevice' => true,
              'includeTechnician' => true,
              'includeUtility' => true,
              'includeManagement' => true,
              'includeAlerts' => true,
              'includeWeather' => true,
              'includeHouseDetails' => true,
              'includeOemCfg' => true,
              'includeEquipmentStatus' => true,
              'includeNotificationSettings' => true,
              'includeVersion' => true,
              'includePrivacy' => true,
              'includeAudio' => true,
              'includeSensors' => true

              /**
               * 'includeReminders' => true
               *
               * While documented, this is not available for general API use
               * unless you are a technician user.
               *
               * The reminders and the includeReminders flag are something extra
               * for ecobee Technicians. It allows them to set and receive
               * reminders with more detail than the usual alert reminder type.
               * These reminders are only available to Technician users, which
               * is why you aren't seeing any new information when you set that
               * flag to true. Thanks for pointing out the lack of documentation
               * regarding this. We'll get this updated as soon as possible.
               *
               *
               * https://getsatisfaction.com/api/topics/what-does-includereminders-do-when-calling-get-thermostat?rfm=1
               */

              /**
               * 'includeSecuritySettings' => true
               *
               * While documented, this is not made available for general API
               * use unless you are a utility. If you try to include this an
               * "Authentication failed" error will be returned.
               *
               * Special accounts such as Utilities are permitted an alternate
               * method of authorization using implicit authorization. This
               * method permits the Utility application to authorize against
               * their own specific account without the requirement of a PIN.
               * This method is limited to special contractual obligations and
               * is not available for 3rd party applications who are not
               * Utilities.
               *
               * https://www.ecobee.com/home/developer/api/documentation/v1/objects/SecuritySettings.shtml
               * https://www.ecobee.com/home/developer/api/documentation/v1/auth/auth-intro.shtml
               *
               */
            ]
          ])
        ]
      ]
    );

    // Loop over the returned sensors and create/update them as necessary.
    $ecobee_sensor_ids_to_keep = [];
    foreach($response['thermostatList'] as $thermostat_api) {
      $guid = sha1($thermostat_api['identifier'] . $thermostat_api['runtime']['firstConnected']);

      $ecobee_thermostat = $this->api(
        'ecobee_thermostat',
        'get',
        [
          'attributes' => [
            'guid' => $guid
          ]
        ]
      );

      $thermostat = $this->api(
        'thermostat',
        'get',
        [
          'attributes' => [
            'ecobee_thermostat_id' => $ecobee_thermostat['ecobee_thermostat_id']
          ]
        ]
      );

      foreach($thermostat_api['remoteSensors'] as $api_sensor) {
        $ecobee_sensor = $this->get(
          [
            'ecobee_thermostat_id' => $ecobee_thermostat['ecobee_thermostat_id'],
            'identifier' => $api_sensor['id']
          ]
        );

        if ($ecobee_sensor !== null) {
          // Sensor exists.
          $sensor = $this->api(
            'sensor',
            'get',
            [
              'attributes' => [
                'ecobee_sensor_id' => $ecobee_sensor['ecobee_sensor_id']
              ]
            ]
          );
        }
        else {
          // Sensor does not exist.
          $ecobee_sensor = $this->create([
            'ecobee_thermostat_id' => $ecobee_thermostat['ecobee_thermostat_id'],
            'identifier' => $api_sensor['id']
          ]);
          $sensor = $this->api(
            'sensor',
            'create',
            [
              'attributes' => [
                'ecobee_sensor_id' => $ecobee_sensor['ecobee_sensor_id'],
                'thermostat_id' => $thermostat['thermostat_id']
              ]
            ]
          );
        }

        $ecobee_sensor_ids_to_keep[] = $ecobee_sensor['ecobee_sensor_id'];

        $this->update(
          [
            'ecobee_sensor_id' => $ecobee_sensor['ecobee_sensor_id'],
            'name' => $api_sensor['name'],
            'type' => $api_sensor['type'],
            'code' => (isset($api_sensor['code']) === true ? $api_sensor['code'] : null),
            'in_use' => ($api_sensor['inUse'] === true ? 1 : 0),
            'json_capability' => $api_sensor['capability'],
            'inactive' => 0
          ]
        );

        $attributes = [];
        $attributes['name'] = $api_sensor['name'];
        $attributes['type'] = $api_sensor['type'];
        $attributes['in_use'] = $api_sensor['inUse'];

        $attributes['temperature'] = null;
        $attributes['humidity'] = null;
        $attributes['occupancy'] = null;
        foreach($api_sensor['capability'] as $capability) {
          switch($capability['type']) {
            case 'temperature':
              if(
                is_numeric($capability['value']) === true &&
                $capability['value'] <= 999.99 &&
                $capability['value'] >= -999.99
              ) {
                $attributes['temperature'] = $capability['value'] / 10;
              } else {
                $attributes['temperature'] = null;
              }
            break;
            case 'humidity':
              if(
                is_numeric($capability['value']) === true &&
                $capability['value'] <= 100 &&
                $capability['value'] >= 0
              ) {
                $attributes['humidity'] = $capability['value'] / 10;
              } else {
                $attributes['humidity'] = null;
              }
            break;
            case 'occupancy':
              $attributes['occupancy'] = $capability['value'] === "true";
            break;
          }

          // Update the sensor.
          $this->api(
            'sensor',
            'update',
            [
              'attributes' => array_merge(
                ['sensor_id' => $sensor['sensor_id']],
                $attributes
              )
            ]
          );
        }
      }
    }

    // Inactivate any sensors that were no longer returned.
    $ecobee_sensors = $this->read();
    foreach($ecobee_sensors as $ecobee_sensor) {
      if(in_array($ecobee_sensor['ecobee_sensor_id'], $ecobee_sensor_ids_to_keep) === false) {
        $this->update(
          [
            'ecobee_sensor_id' => $ecobee_sensor['ecobee_sensor_id'],
            'inactive' => 1
          ]
        );

        $this->api(
          'sensor',
          'update',
          [
            'attributes' => [
              'sensor_id' => $sensor['sensor_id'],
              'inactive' => 1
            ]
          ]
        );
      }
    }

    $return = $this->read_id(['ecobee_sensor_id' => $ecobee_sensor_ids_to_keep]);

    return $return;
  }

}
