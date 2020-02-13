<?php
// application.php
require __DIR__ . '/../../../../../vendor/autoload.php';

use Symfony\Component\Console\Application;

$application = new Application();

// ... register commands
$roadrunnerDaemonCommand = new \Raketman\RoadrunnerDaemon\Command\StartDaemonCommand('raketman:roadrunner:daemon');
$roadrunnerDaemonCommand->setPoolsResolver(new \Raketman\RoadrunnerDaemon\Service\PoolResolver(__DIR__ . '/../pools'));

$application->add($roadrunnerDaemonCommand);

$application->run();

