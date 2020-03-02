<?php

namespace Raketman\RoadrunnerDaemon\Structure;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Pool
{
    protected $isRpc = false;

    /**
     * @var null уникальный хэш команды, по которому будет идти отслеживание скрипта
     *    измениться $key, скрипт убьет процесси запустит новый, это нужно для того, чтобы имелась
     *    возможность динамически менять/добавлять/удалять пулы
     */
    protected $key = null;

    protected $configPath = null;

    public function __construct($key, $configPath, $isRpc = true)
    {
        $this->key = $key;
        $this->configPath = $configPath;
        $this->isRpc = $isRpc;
    }

    /**
     * @return bool
     */
    public function isRpc(): bool
    {
        return $this->isRpc;
    }

    /**
     * @return null
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @return null
     */
    public function getConfigPath()
    {
        return $this->configPath;
    }




}
