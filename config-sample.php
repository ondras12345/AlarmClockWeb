<?php

/*
 * Configuration for AlarmClockWeb
 *
 * Copy this file to config.php and fill in the values.
 */

define('MQTT_TOPIC_PREFIX', 'alarmclock');

define('MQTT_HOST', 'localhost');
define('MQTT_PORT', 1883);
// TODO allow MQTT without auth
define('MQTT_USERNAME', 'username');
define('MQTT_PASSWORD', 'password');


define('DEBUG_MODE', false);

?>
