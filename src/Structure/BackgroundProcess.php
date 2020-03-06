<?php

namespace Raketman\RoadrunnerDaemon\Structure;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

class BackgroundProcess extends Process
{
    /** @var Pool */
    protected $pool;

    protected $lastResetTime = null;

    /**
     * @return Pool
     */
    public function getPool(): Pool
    {
        return $this->pool;
    }

    /**
     * @param Pool $pool
     */
    public function setPool(Pool $pool): void
    {
        $this->pool = $pool;
    }

    public function run($callback = null)
    {
        $this->lastResetTime = time(); // Фиксируем время старта
        return parent::run($callback);
    }

    /**
     * @return null
     */
    public function getLastResetTime()
    {
        return $this->lastResetTime;
    }

    /**
     * @param null $lastResetTime
     */
    public function setLastResetTime($lastResetTime)
    {
        $this->lastResetTime = $lastResetTime;
    }
    
}
