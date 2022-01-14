<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style.css">
  <title>Alarms</title>
</head>
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

$MQTT_TOPIC_PREFIX = MQTT_TOPIC_PREFIX;

$client = new MqttClient(MQTT_HOST, MQTT_PORT);
$connection_settings = (new ConnectionSettings)
  ->setUsername(MQTT_USERNAME)
  ->setPassword(MQTT_PASSWORD);
$client->connect($connection_settings, true);

$number_of_alarms = 0;
$alarms = array();

$client->subscribe("$MQTT_TOPIC_PREFIX/stat/number_of_alarms",
  function(string $topic, string $message, bool $retained) use ($client, &$number_of_alarms)
  {
    // Needed to fix a potential race condition during second loop
    if ($number_of_alarms != 0)
      return;
    $number_of_alarms = $message;
    $client->interrupt();
  });
$client->loop(true);

$client->subscribe("$MQTT_TOPIC_PREFIX/stat/alarms/+",
  function(string $topic, string $message, bool $retained) use ($client, $number_of_alarms, &$alarms)
  {
    $alarm = json_decode($message, false);
    $matches = array();
    preg_match_all('/alarms\/alarm([0-9]*)/', $topic, $matches);
    $index = $matches[1][0];
    $alarm->index = $index;
    $alarm->json = $message;
    array_push($alarms, $alarm);

    $found_all = true;
    for ($i = 0; $i < $number_of_alarms; $i++)
    {
      $found_current = false;
      foreach ($alarms as $alarm)
      {
        if ($alarm->index == $i)
        {
          $found_current = true;
          break;
        }
      }
      if (!$found_current)
      {
        $found_all = false;
        break;
      }
    }
    if ($found_all)
    {
      // After receiving the first message on the subscribed topic, we want the
      // client to stop listening for messages.
      $client->interrupt();
    }
  });

$client->publish("$MQTT_TOPIC_PREFIX/cmnd/alarms", "?");
$client->loop(true);
$client->disconnect();

usort($alarms, function ($a, $b) {
  return $a->index - $b->index;
});

//var_dump($alarms);
?>
<body>
  <h1>Alarm Clock</h1>
  <h2>Alarms</h2>
  <p>This simple UI allows for reading and writing configuration of alarms.
  Only one alarm can be written at a time, and other alarms' configuration will
  be lost in the process. So don't modify multiple alarms simultaneously.</p>
  <p>TODO implement writing alarms</p>
  <table border class="table-alarms">
    <thead>
      <tr>
        <th>Index</th>
        <th>Configuration</th>
      </tr>
    </thead>
    <tbody>
<?php
foreach($alarms as $alarm)
{
  // id prefix
  $idp = "a{$alarm->index}";
  echo <<<EOF
      <tr>
        <td>{$alarm->index}</td>
        <td>
          <form method="post">
            <input type="hidden" name="index" value="{$alarm->index}">
            <table border>
              <tr>
                <td><label for="$idp-enabled">Enabled</label></td>
                <td>
                  <select name="enabled" id="$idp-enabled" autocomplete="off">
                    <option hidden>unknown</option><!-- default - in case there was no 'selected' option -->

EOF;
  $enabled_options = array('OFF', 'SGL', 'RPT', 'SKP');
  foreach ($enabled_options as $option)
  {
    $option_selected = $alarm->enabled == $option ? " selected": "";
    echo <<<EOF
                    <option$option_selected>
                      $option
                    </option>

EOF;
  }
  echo <<<EOF
                  </select>
                </td>
              </tr>
              <tr>
                <td>Days of week</td>
                <td>

EOF;
  $dow = array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
  foreach ($dow as $option)
  {
    $checked = (in_array($option, $alarm->days_of_week) ? " checked": "");
    echo <<<EOF
                    <label for="$idp-dow-$option" class="nowrap">
                      <input type="checkbox" name="dow-$option" id="$idp-dow-$option" value="$option"$checked>
                      $option
                    </label>

EOF;
  }

  $time = "";
  if ($alarm->time->hours < 10) $time .= "0";
  $time .= "{$alarm->time->hours}:";
  if ($alarm->time->minutes < 10) $time .= "0";
  $time .= "{$alarm->time->minutes}";

  $lamp_on_checked = $alarm->signalization->lamp ? " checked" : "";
  $lamp_off_checked = $alarm->signalization->lamp ? "" : " checked";
  echo <<<EOF
                </td>
              </tr>
              <tr>
                <td><label for="$idp-time">Time</label></td>
                <td>
                  <input type="time" id="$idp-time" name="time" value="$time">
                </td>
              </tr>
              <tr>
                <td><label for="$idp-snztime">Snooze time</label></td>
                <td>
                  <input type="number" id="$idp-snztime" name="snztime" min="0" max="99" step="1" value="{$alarm->snooze->time}">&nbsp;min
                </td>
              </tr>
              <tr>
                <td><label for="$idp-snzcount">Snooze count</label></td>
                <td>
                  <input type="number" id="$idp-snzcount" name="snztime" min="0" max="9" step="1" value="{$alarm->snooze->count}">
                </td>
              </tr>
              <tr>
                <td><label for="$idp-sigambient">Ambient</label></td>
                <td>
                  <input type="number" id="$idp-sigambient" name="sigambient" min="0" max="255" step="1" value="{$alarm->signalization->ambient}">
                </td>
              </tr>
              <tr>
                <td>Lamp</td>
                <td>
                  <input type="radio" id="$idp-sig-lamp-off" name="sig-lamp" value="0"$lamp_off_checked>
                  <label for="$idp-sig-lamp-off">lamp off</label>
                  <input type="radio" id="$idp-sig-lamp-on" name="sig-lamp" value="1"$lamp_on_checked>
                  <label for="$idp-sig-lamp-on">lamp on</label>
                </td>
              </tr>
              <tr>
                <td><label for="$idp-sig-buzzer">Buzzer</label></td>
                <td>
                  <select name="sig-buzzer" id="$idp-sig-buzzer" autocomplete="off">
                    <option hidden>unknown</option><!-- default - in case there was no 'selected' option -->

EOF;
  $buzzer_options = array(
    0 => 'off',
    1 => 'beeping'
  );
  for ($i = 0; $i<16; $i++)
    $buzzer_options[$i+10] = "melody $i";

  foreach ($buzzer_options as $option_number => $option_text)
  {
    $option_selected = ($alarm->signalization->buzzer == $option_number ? " selected": "");
    echo <<<EOF
                    <option value="$option_number"$option_selected>
                      $option_text
                    </option>

EOF;
  }
  $json_pretty = json_encode(json_decode($alarm->json), JSON_PRETTY_PRINT);
  echo <<<EOF
                  </select>
                </td>
              </tr>
            </table>
            <details>
              <summary>raw json</summary>
              <pre><code>$json_pretty</code></pre>
            </details>
            <input type="submit" value="Write alarm{$alarm->index}">
          </form>
        </td>
      </tr>
EOF;
}
?>
    </tbody>
  </table>
</body>
<!-- TODO handle POST requests -->
</html>
