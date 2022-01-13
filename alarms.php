<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Alarms</title>
</head>
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

$alarms = array();
$number_of_alarms = 0;

$client->subscribe("$MQTT_TOPIC_PREFIX/stat/number_of_alarms",
  function(string $topic, string $message, bool $retained) use ($client, &$number_of_alarms)
  {
    $number_of_alarms = $message;
    // TODO race condition in second loop ?!
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

foreach($alarms as $alarm)
{
  echo "<tr><td>{$alarm->index}</td>";
  echo "<td><table border><thead><tr><th>Key</th><th>Value</th></thead><tbody>";
  echo "<tr><td>Enabled</td><td>{$alarm->enabled}</td>";
  echo "<tr><td>Days of week</td><td>" . implode(', ', $alarm->days_of_week) . "</td>";
  echo "<tr><td>Time</td><td>{$alarm->time->hours}:{$alarm->time->minutes}</td>";
  echo "<tr><td>Snooze time</td><td>{$alarm->snooze->time} min</td>";
  echo "<tr><td>Snooze count</td><td>{$alarm->snooze->count}</td>";
  echo "<tr><td>Ambient</td><td>{$alarm->signalization->ambient}</td>";
  echo "<tr><td>Lamp</td><td>" . ($alarm->signalization->lamp ? "yes" : "no") . "</td>";
  echo "<tr><td>Buzzer</td><td>{$alarm->signalization->buzzer}</td>";
  echo "</tbody></table></td></tr>";
}
    ?>
    </tbody>
  </table>
</body>
</html>
