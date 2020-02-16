<?php

namespace Raketman\RoadrunnerDaemon\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PoolResolver implements PoolResolverInterface
{
    protected $poolPath;
    protected $updateInterval;

    public function __construct($poolPath, $updateInterval = 60)
    {
        $this->poolPath = $poolPath;
        $this->updateInterval = $updateInterval;
    }

    public function getPools()
    {
        $result = [];

        // Проверим наличие класса в директориях каталога с классами
        $directoryIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->poolPath));
        while ($directoryIterator->valid()) {
            $currentFilePath = $directoryIterator->key();
            // Нас интересуют только файлы
            if (is_file($currentFilePath)) {
                $key = md5(file_get_contents($currentFilePath));
                $result[$key] = $currentFilePath;
            }

            $directoryIterator->next();
        }

        return $result;
    }

    public function revisionInterval()
    {
        return $this->updateInterval;
    }
}
