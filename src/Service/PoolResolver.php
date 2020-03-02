<?php

namespace Raketman\RoadrunnerDaemon\Service;

use Raketman\RoadrunnerDaemon\Structure\Pool;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Parser;

class PoolResolver implements PoolResolverInterface
{
    protected $poolPath;
    protected $updateInterval;
    protected $rwInterval;

    public function __construct($poolPath, $updateInterval = 60, $rwInterval = 14400)
    {
        $this->poolPath = $poolPath;
        $this->updateInterval = $updateInterval;
        $this->rwInterval = $rwInterval;
    }

    public function getPools()
    {
        $result = [];
        $parser= new Parser();

        // Проверим наличие класса в директориях каталога с классами
        $directoryIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->poolPath));
        while ($directoryIterator->valid()) {
            $currentFilePath = $directoryIterator->key();
            // Нас интересуют только файлы
            if (is_file($currentFilePath)) {
                $key = md5(file_get_contents($currentFilePath));

                // Спас
                $config = $parser->parse(file_get_contents($currentFilePath));

                // По умолчанию rpc включен
                $isRpc = !isset($config['rpc']) || $config['rpc'];
                $result[$key] = new Pool($key, $currentFilePath, $isRpc);
            }

            $directoryIterator->next();
        }

        return array_values($result);
    }

    public function revisionInterval()
    {
        return $this->updateInterval;
    }


    public function resetWorkerInterval()
    {
        $this->rwInterval;
    }
}
