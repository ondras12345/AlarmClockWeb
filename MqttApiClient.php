<?php
namespace MqttApiClient;

require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class QueryResult
{
  public $number_of_alarms = 0;
  public $alarms = array();
  public $mqtt_errors = "";
  public $write_with_no_changes = false;
  public $write_log = "";

}

function query(?array $alarms_to_write = null): QueryResult
{
  // I cannot make a function that only writes alarms without reading, because
  // I wouldn't know when to unsubscribe from err topic.

  $result = new QueryResult();

  $MQTT_TOPIC_PREFIX = MQTT_TOPIC_PREFIX;

  $client = new MqttClient(MQTT_HOST, MQTT_PORT);
  $connection_settings = (new ConnectionSettings)
    ->setUsername(MQTT_USERNAME)
    ->setPassword(MQTT_PASSWORD);
  $client->connect($connection_settings, true);

  $client->subscribe("$MQTT_TOPIC_PREFIX/err",
    function(string $topic, string $message, bool $retained) use (&$result)
    {
      $result->mqtt_errors .= $message . "\n";
    });


  if (!is_null($alarms_to_write))
  {
    $result->write_with_no_changes = true;
    foreach ($alarms_to_write as $alarm)
    {
      if ($alarm->changed)
      {
        $result->write_log .= "writing alarm {$alarm->index}\n";
        $result->write_with_no_changes = false;
        $client->publish("$MQTT_TOPIC_PREFIX/cmnd/alarm/write", json_encode($alarm));
      }
    }
  }

  $client->subscribe("$MQTT_TOPIC_PREFIX/stat/number_of_alarms",
    function(string $topic, string $message, bool $retained) use ($client, &$result)
    {
      // Needed to fix a potential race condition during second loop
      if ($result->number_of_alarms != 0)
        return;
      $result->number_of_alarms = $message;
      $client->interrupt();
    });
  $client->loop(true);

  $client->subscribe("$MQTT_TOPIC_PREFIX/stat/alarms/+",
    function(string $topic, string $message, bool $retained) use ($client, &$result)
    {
      if ($retained) return;  // ignore old retained messages, wait for new read
      $alarm = json_decode($message, false);
      $matches = array();
      preg_match_all('/alarms\/alarm([0-9]*)/', $topic, $matches);
      $index = $matches[1][0];
      $alarm->index = $index;
      $alarm->json = $message;
      array_push($result->alarms, $alarm);

      $found_all = true;
      for ($i = 0; $i < $result->number_of_alarms; $i++)
      {
        $found_current = false;
        foreach ($result->alarms as $alarm)
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

  usort($result->alarms, function ($a, $b) {
      return $a->index - $b->index;
    });

  return $result;
}

?>
