<?php

use Spiral\Goridge\StreamRelay;
use Spiral\RoadrunnerDaemon\PSR7Client;
use Spiral\RoadrunnerDaemon\Worker;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\Debug\Debug;
use Symfony\Component\HttpFoundation\Response;

require __DIR__ . '/../app/bootstrap.php.cache';
require_once __DIR__.'/../app/AppKernel.php';

$env   = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';
$debug = ('dev' === $env);

if ($debug) {
    umask(0000);

    Debug::enable();
}

$kernel = new AppKernel($env, $debug);
$kernel->boot();
$relay                    = new Spiral\Goridge\StreamRelay(STDIN, STDOUT);
$psr7                     = new \Spiral\RoadRunner\PSR7Client(new \Spiral\RoadRunner\Worker($relay));
$httpFoundationFactory    = new HttpFoundationFactory();
$diactorosFactory         = new DiactorosFactory();

$logger = $kernel->getContainer()->get('logger');
while ($req = $psr7->acceptRequest()) {
    try {
        $request = $httpFoundationFactory->createRequest($req);
        $response = $kernel->handle($request);
    } catch (\Raketman\RoadrunnerDaemon\Exceptions\CriticalException $e) {
        $logger->error('worker-restart', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        $psr7->getWorker()->stop();
        return;
    } catch (\Throwable $e) {
        $logger->error('worker-error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        $response =  new \Symfony\Component\HttpFoundation\JsonResponse([
            'error' => [
                'type'      => 'server_error',
                'message'   => Response::$statusTexts[Response::HTTP_INTERNAL_SERVER_ERROR]
            ]
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $psr7->respond($diactorosFactory->createResponse($response));
    $kernel->terminate($request, $response);
}
