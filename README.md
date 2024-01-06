# AlarmClockWeb
This is a web interface for [AlarmClock][AlarmClock]. It uses the MQTT API
(`ac2mqtt`) that is part of [PyAlarmClock][PyAlarmClock].


## Docker
To make this easier to use on Debian 10 (buster), which does not package PHP
8 needed by the MQTT client library, a `Dockerfile` is provided.
```
cp config-sample.php config.php
# edit your config.php
docker build -t alarmclockweb .
docker run -d \
    --name alarmclockweb \
    -p 80:80 \
    -v $PWD/config.php:/var/www/html/config.php:ro \
    alarmclockweb
```


[AlarmClock]: https://github.com/ondras12345/AlarmClock
[PyAlarmClock]: https://github.com/ondras12345/PyAlarmClock
