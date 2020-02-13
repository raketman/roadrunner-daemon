<?php

namespace Raketman\RoadrunnerDaemon\Service;

interface PoolResolverInterface
{
    /**
     * @return array [
     *      $key1 => $command1
     *      $key2 => $command2
     * ], где $key - уникальный хэш команды, по которому будет идти отслеживание скрипта
     *    измениться $key, скрипт убьет процесси запустит новый, это нужно для того, чтобы имелась
     *    возможность динамически менять/добавлять/удалять пулы
     */
    public function getPools();

    /**
     * Периодичность обновления пулов
     * @return mixed
     */
    public function revisionInterval();
}
