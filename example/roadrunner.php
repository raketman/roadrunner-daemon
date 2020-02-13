<?php
register_shutdown_function(function() {
    print_r(error_get_last());
});

use App\Kernel;
use Spiral\Goridge\SocketRelay;
use Spiral\Goridge\StreamRelay;
use Spiral\RoadrunnerDaemon\PSR7Client;
use Spiral\RoadrunnerDaemon\Worker;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\Debug\Debug;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/../../../../config/bootstrap.php';

$env   = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
$debug = (bool)($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? ('prod' !== $env));

if ($debug) {
    umask(0000);

    Debug::enable();
}

if ($trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? false) {
    Request::setTrustedProxies(explode(',', $trustedProxies), Request::HEADER_X_FORWARDED_ALL ^ Request::HEADER_X_FORWARDED_HOST);
}

if ($trustedHosts = $_SERVER['TRUSTED_HOSTS'] ?? $_ENV['TRUSTED_HOSTS'] ?? false) {
    Request::setTrustedHosts(explode(',', $trustedHosts));
}

$kernel = new Kernel($env, $debug);
$kernel->boot();
$rebootKernelAfterRequest = in_array('--reboot-kernel-after-request', $argv);
//$relay                    = new SocketRelay('/tmp/road-runner.sock', null, SocketRelay::SOCK_UNIX);
$relay                    = new Spiral\Goridge\StreamRelay(STDIN, STDOUT);
$psr7                     = new \Spiral\RoadRunner\PSR7Client(new \Spiral\RoadRunner\Worker($relay));
$httpFoundationFactory    = new HttpFoundationFactory();
$diactorosFactory         = new DiactorosFactory();

while ($req = $psr7->acceptRequest()) {
    try {
        $request  = $httpFoundationFactory->createRequest($req);
        $response = $kernel->handle($request);
        $psr7->respond($diactorosFactory->createResponse($response));
        $kernel->terminate($request, $response);
        if($rebootKernelAfterRequest) {
            $kernel->reboot(null);
        }
    } catch (\Throwable $e) {
        $psr7->getWorker()->error((string)$e);
    }
}