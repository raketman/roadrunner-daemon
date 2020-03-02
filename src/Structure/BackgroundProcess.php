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


}
