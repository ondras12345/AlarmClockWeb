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
  <table border>
    <thead>
      <tr>
        <th>Index</th>
        <th>Configuration</th>
      </tr>
    </thead>
    <tbody>
<?php foreach($alarms as $alarm): ?>
      <tr>
        <td><?php echo $alarm->index; ?></td>
        <td>
          <form method="post">
            <input type="hidden" id="index" name="index" value="<?php echo $alarm->index; ?>">
            <table border>
              <tr>
                <td><label for="enabled">Enabled</label></td>
                <td>
                  <select name="enabled" id="enabled" autocomplete="off">
                    <option hidden>unknown</option><!-- default - in case there was no 'selected' option -->
<?php
$enabled_options = array('OFF', 'SGL', 'RPT', 'SKP');
foreach ($enabled_options as $option) : ?>
                    <option <?php echo ($alarm->enabled == $option ? 'selected': ''); ?>>
                      <?php echo $option . "\n"; ?>
                    </option>
<?php endforeach; ?>
                  </select>
                </td>
              </tr>
              <tr>
                <td>Days of week</td>
                <td>
<?php
$dow = array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
foreach ($dow as $option) : ?>
                    <input type="checkbox" name="dow-<?php echo $option; ?>" id="dow-<?php echo $option; ?>" value="<?php echo $option; ?>"<?php echo (in_array($option, $alarm->days_of_week) ? ' checked': ''); ?>>
                    <label for="dow-<?php echo $option; ?>"><?php echo $option; ?></label>
<?php endforeach; ?>
                </td>
              </tr>
              <tr>
                <td><label for="time">Time</label></td>
                <td>
                  <input type="time" id="time" name="time" value="<?php
  if ($alarm->time->hours < 10) echo "0";
  echo "{$alarm->time->hours}:";
  if ($alarm->time->minutes < 10) echo "0";
  echo "{$alarm->time->minutes}";
?>">
                </td>
              </tr>
              <tr>
                <td><label for="snztime">Snooze time</label></td>
                <td>
                  <input type="number" id="snztime" name="snztime" min="0" max="99" step="1" value="<?php echo $alarm->snooze->time; ?>">&nbsp;min
                </td>
              </tr>
              <tr>
                <td><label for="snzcount">Snooze count</label></td>
                <td>
                  <input type="number" id="snzcount" name="snztime" min="0" max="9" step="1" value="<?php echo $alarm->snooze->count; ?>">
                </td>
              </tr>
              <tr>
                <td><label for="sigambient">Ambient</label></td>
                <td>
                  <input type="number" id="sigambient" name="sigambient" min="0" max="255" step="1" value="<?php echo $alarm->signalization->ambient; ?>">
                </td>
              </tr>
              <tr>
                <td>Lamp</td>
                <td>
                  <input type="radio" id="sig-lamp-off" name="sig-lamp" value="0" <?php echo $alarm->signalization->lamp ? "" : "checked"; ?>>
                  <label for="sig-lamp-off">lamp off</label>
                  <input type="radio" id="sig-lamp-on" name="sig-lamp" value="1" <?php echo $alarm->signalization->lamp ? "checked" : ""; ?>>
                  <label for="sig-lamp-on">lamp on</label>
                </td>
              </tr>
              <tr>
                <td><label for="sig-buzzer">Buzzer</label></td>
                <td>
                  <select name="sig-buzzer" id="sig-buzzer" autocomplete="off">
                    <option hidden>unknown</option><!-- default - in case there was no 'selected' option -->
<?php
$buzzer_options = array(
  0 => 'off',
  1 => 'beeping'
);
for ($i = 0; $i<16; $i++)
  $buzzer_options[$i+10] = "melody $i";

foreach ($buzzer_options as $option_number => $option_text) : ?>
                    <option value="<?php echo $option_number; ?>" <?php echo ($alarm->signalization->buzzer == $option_number ? 'selected': ''); ?>>
                      <?php echo $option_text . "\n"; ?>
                    </option>
<?php endforeach; ?>
                  </select>
                </td>
              </tr>
            </table>
            <input type="submit">
          </form>
        </td>
      </tr>
<!-- TODO labels refer to wrong forms -->
<!-- TODO handle POST requests -->
<!-- TODO show / copy json -->
<?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
