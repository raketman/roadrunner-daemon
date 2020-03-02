<?php

namespace Raketman\RoadrunnerDaemon\Service;

use Raketman\RoadrunnerDaemon\Structure\Pool;

interface PoolResolverInterface
{

    /**
     * @return Pool[]
     */
    public function getPools();

    /**
     * Периодичность обновления пулов
     * @return mixed
     */
    public function revisionInterval();

    /**
     * Периодичность обновления воркеров в пулах
     * @return mixed
     */
    public function resetWorkerInterval();
}
