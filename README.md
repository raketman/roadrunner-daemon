Для запуска скрипта создать конфигурационный файл:

```$xslt
pid-file: /run/raketman-daemon # пусть к pid файлу
lock-by-pid: false # блокировка по пиду, есди не используется timer
max-execution-time: 640000  # время выполнение, после которого он завершает работу
sleep: 100 # время в микросекундах, которое тратиться на прерывания скрипта,чтобы не нагружать процессор
```

Пример запуска можно посмотреть на example/bin/application.php
А также на сайте офф документации https://roadrunner.dev/docs/php-worker


Для  подключения в проект symfony вам надо:

```
services:

    app.roadrunner.resolver:
        class: Raketman\RoadrunnerDaemon\Service\PoolResolver
        arguments: ['path to worker pools']

    app.roadrunner.daemon:
        class: Raketman\RoadrunnerDaemon\Command\StartDaemonCommand
        calls:
            - [setLogger, ['@logger']]
            - [setPoolsResolver, ['@app.roadrunner.resolver']]
        tags:
            - { name: console.command }
```

Для запуска 
```
php bin/console raketman:roadrunner:daemon path_to_config
```


Также можно запустить через 

```
php vendor/raketman/roadrunner-daemon/example/bin/application.php raketman:roadrunner:daemon vendor/raketman/roadrunner-daemon/example/config/example.yaml 
```




Также необходимо учитывать автозагрузку psr-4

```
"autoload": {
        "psr-4": {
            "App\\": "src/",
            "Raketman\\RoadrunnerDaemon\\": "vendor/raketman/roadrunner-daemon/src"
        }
    },
```