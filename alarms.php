<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="/style.css">
  <title>Alarms</title>
  <script>
    function calcSnooze(idp)
    {
      var time = parseInt(document.getElementById(idp+"-snztime").value);
      var count = parseInt(document.getElementById(idp+"-snzcount").value);
      var res = time*count;
      document.getElementById(idp+"-time-snooze").innerHTML = "+ "+res+"&nbsp;min snooze";
    }
  </script>
</head>
<?php
require __DIR__ . '/config.php';
require __DIR__ . '/MqttApiClient.php';


class ParsingFailed extends Exception {}

function parsePost()
{
  // empty() considers '0' empty, so I need to use !isset when 0 is a valid
  // input.

  if (empty($_POST["number_of_alarms"]))
    throw new ParsingFailed("No number_of_alarms");

  $n_alarms = intval($_POST["number_of_alarms"]);
  if ($n_alarms <= 0)
    throw new ParsingFailed("Negative number_of_alarms");

  $alarms = array();
  for ($i = 0; $i < $n_alarms; $i++)
  {
    $alarm = new stdClass();

    if (empty($_POST["a$i-enabled"]))
      throw new ParsingFailed("No a$i-enabled");
    $alarm->enabled = htmlspecialchars(trim($_POST["a$i-enabled"]));

    $dow = array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
    $days = array();
    foreach ($dow as $day)
    {
      if (!empty($_POST["a$i-dow-$day"]))
        array_push($days, $day);
    }
    $alarm->days_of_week = $days;

    $alarm->time = new stdClass();
    if (empty($_POST["a$i-time"]))
      throw new ParsingFailed("No a$i-time");
    $time = trim($_POST["a$i-time"]);
    $alarm->time->hours = intval(explode(':', $time)[0]);
    $alarm->time->minutes = intval(explode(':', $time)[1]);
    if ($alarm->time->hours < 0 || $alarm->time->hours > 23 ||
        $alarm->time->minutes < 0 || $alarm->time->minutes > 59
        )
        throw new ParsingFailed("Invalid a$i-time");

    $alarm->snooze = new stdClass();
    if (!isset($_POST["a$i-snztime"]))
      throw new ParsingFailed("No a$i-snztime");
    $snztime = intval($_POST["a$i-snztime"]);
    if ($snztime < 0 || $snztime > 99)
      throw new ParsingFailed("Invalid a$i-snztime");
    $alarm->snooze->time = $snztime;

    if (!isset($_POST["a$i-snzcount"]))
      throw new ParsingFailed("No a$i-snzcount");
    $snzcount = intval($_POST["a$i-snzcount"]);
    if ($snzcount < 0 || $snzcount > 9)
      throw new ParsingFailed("Invalid a$i-snztime");
    $alarm->snooze->count = $snzcount;

    $alarm->signalization = new stdClass();
    if (!isset($_POST["a$i-sig-ambient"]))
      throw new ParsingFailed("No a$i-sig-ambient");
    $amb = intval($_POST["a$i-sig-ambient"]);
    if ($amb < 0 || $amb > 255)
      throw new ParsingFailed("Invalid a$i-sig-ambient");
    $alarm->signalization->ambient = $amb;

    if (!isset($_POST["a$i-sig-lamp"]))
      throw new ParsingFailed("No a$i-sig-lamp");
    $lamp = intval($_POST["a$i-sig-lamp"]);
    if ($lamp != 0 && $lamp != 1)
      throw new ParsingFailed("Invalid a$i-sig-lamp");
    $alarm->signalization->lamp = $lamp;

    if (!isset($_POST["a$i-sig-buzzer"]))
      throw new ParsingFailed("No a$i-sig-buzzer");
    $buzzer = intval($_POST["a$i-sig-buzzer"]);
    if ($buzzer < 0 || $buzzer > 255)
      throw new ParsingFailed("Invalid a$i-sig-buzzer");
    $alarm->signalization->buzzer = $buzzer;

    if (empty($_POST["a$i-original_json"]))
      throw new ParsingFailed("No a$i-original_json");
    $original = json_decode(trim($_POST["a$i-original_json"]));

    $alarm->changed = $alarm != $original;
    $alarm->index = $i;

    array_push($alarms, $alarm);
  }

  return $alarms;
}


$post = false;
$new_alarms = null;
$parsing_errors = null;
if ($_SERVER["REQUEST_METHOD"] == "POST")
{
  $post = true;

  try {
    $new_alarms = parsePost();
  }
  catch (ParsingFailed $e) {
    $parsing_errors = $e->getMessage() . "\n";
  }
}


$result = MqttApiClient\query($new_alarms);

?>
<body>
  <header>
    <nav>
      <ul class="navbar" id="alarms">
        <?php include 'menu_items.php'; ?>
      </ul>
    </nav>
  </header>
  <h1>Alarms</h1>
  <p>This simple UI allows for reading and writing configuration of alarms.</p>
<?php
if (!empty($result->write_log))
{
  echo <<<EOF
  <pre class="log">{$result->write_log}</pre>

EOF;
}

if (!empty($resut->mqtt_errors))
{
  echo <<<EOF
  <pre class="error">{$result->mqtt_errors}</pre>

EOF;
}

if(!empty($parsing_errors))
{
  echo <<<EOF
  <p class="error">
    $parsing_errors
  </p>

EOF;
}

if($result->write_with_no_changes)
{
  echo <<<EOF
  <p class="error">
    Attempted write with no changes.
  </p>

EOF;
}
?>
  <!-- TODO post/redirect/get; errors in session (requires aditional mqtt loop after writing ?!) -->
  <form method="post">
    <input type="hidden" name="number_of_alarms" value="<?php echo $result->number_of_alarms; ?>">
    <table border class="table-alarms">
      <thead>
        <tr>
          <th>Index</th>
          <th>Configuration</th>
        </tr>
      </thead>
      <tbody>
<?php
foreach($result->alarms as $alarm)
{
  // id prefix
  $idp = "a{$alarm->index}";
  echo <<<EOF
        <tr>
          <td>{$alarm->index}</td>
          <td>
            <table border>
              <tr>
                <td><label for="$idp-enabled">Enabled</label></td>
                <td>
                  <select name="$idp-enabled" id="$idp-enabled" autocomplete="off">
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
                      <input type="checkbox" name="$idp-dow-$option" id="$idp-dow-$option" value="$option"$checked>
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
                  <input type="time" id="$idp-time" name="$idp-time" value="$time">
                  <span class="calculated-value" id="$idp-time-snooze"></span>
                </td>
              </tr>
              <tr>
                <td><label for="$idp-snztime">Snooze time</label></td>
                <td>
                  <input type="number" id="$idp-snztime" name="$idp-snztime" min="0" max="99" step="1" value="{$alarm->snooze->time}" oninput="calcSnooze('$idp')">&nbsp;min
                </td>
              </tr>
              <tr>
                <td><label for="$idp-snzcount">Snooze count</label></td>
                <td>
                  <input type="number" id="$idp-snzcount" name="$idp-snzcount" min="0" max="9" step="1" value="{$alarm->snooze->count}" oninput="calcSnooze('$idp')">
                </td>
              </tr>
              <script>calcSnooze('$idp');</script><!-- initial value -->
              <tr>
                <td><label for="$idp-sig-ambient">Ambient</label></td>
                <td>
                  <input type="number" id="$idp-sig-ambient" name="$idp-sig-ambient" min="0" max="255" step="1" value="{$alarm->signalization->ambient}">
                </td>
              </tr>
              <tr>
                <td>Lamp</td>
                <td>
                  <input type="radio" id="$idp-sig-lamp-off" name="$idp-sig-lamp" value="0"$lamp_off_checked>
                  <label for="$idp-sig-lamp-off">lamp off</label>
                  <input type="radio" id="$idp-sig-lamp-on" name="$idp-sig-lamp" value="1"$lamp_on_checked>
                  <label for="$idp-sig-lamp-on">lamp on</label>
                </td>
              </tr>
              <tr>
                <td><label for="$idp-sig-buzzer">Buzzer</label></td>
                <td>
                  <select name="$idp-sig-buzzer" id="$idp-sig-buzzer" autocomplete="off">
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
  $json_html = htmlspecialchars($alarm->json);
  echo <<<EOF
                  </select>
                </td>
              </tr>
            </table>
            <details>
              <summary>raw json</summary>
              <pre><code>$json_pretty</code></pre>
            </details>
            <input type="hidden" name="$idp-original_json" value="$json_html">
          </td>
        </tr>
EOF;
}
?>
      </tbody>
    </table>
    <input type="submit" value="Write changes">
  </form>
<?php
if ($post && DEBUG_MODE)
{
  echo <<<EOF
  <h3>POST debug</h3>
    <code><pre>
EOF;
  var_dump($_POST);
  echo "</pre></code>\n";

  echo "<p>Parsed:</p>";
  echo "<code><pre>";
  var_dump($new_alarms);
  echo "</pre></code>\n";
  if (!is_null($new_alarms))
  {
    foreach ($new_alarms as $alarm)
    {
      if ($alarm->changed)
        echo "<p>Alarm {$alarm->index} has changed.</p>\n";
    }
  }
  else echo $parsing_errors;
}
?>
</body>
</html>
