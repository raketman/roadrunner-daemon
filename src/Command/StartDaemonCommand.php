<?php
declare(ticks = 1);
namespace Raketman\RoadrunnerDaemon\Command;

use Raketman\RoadrunnerDaemon\Service\PoolResolverInterface;
use Raketman\RoadrunnerDaemon\Structure\BackgroundProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Psr\Log\LoggerInterface;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Parser;

class StartDaemonCommand extends Command
{
    protected $pidPath;

    /**
     *
     * @var int
     */
    protected $pid;

    // Время выполнения команды в секундах
    protected $executionTime;

    // Максимально-допустимое время работы команды в секундах
    protected $maxExecutionTime;

    /** @var float */
    protected $usleepDelay;

    /** @var PoolResolverInterface */
    protected $poolsResolver;

    /** @var Process[] */
    protected $processList = [];

    /** @var \Psr\Log\LoggerInterface */
    protected $logger;

    /** @var array */
    protected $config;

    protected $lockByPid;

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param PoolResolverInterface $poolsResolver
     */
    public function setPoolsResolver(PoolResolverInterface $poolsResolver): void
    {
        $this->poolsResolver = $poolsResolver;
    }

    public function __construct(string $name = null)
    {
        parent::__construct($name);

        $this->logger = new \Psr\Log\NullLogger;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        parent::configure();
        $this->setName('raketman:roadrunner:daemon')
            ->setDescription('Запускает демон, который запустит и будет следить за исполнением сконфигурированных фоновых процессов')
            ->addArgument('action', InputArgument::REQUIRED, 'start|stop')
            ->addArgument('config', InputArgument::REQUIRED, 'Файл с конфигурацией необходимых процессов')

            ->addOption('max-execution-time', null, InputOption::VALUE_REQUIRED, 'Максимальное время выполнения команды в секундах', null)
            ->addOption('pid-file', null, InputOption::VALUE_REQUIRED, 'PID файл', null)
            ->addOption('lock-by-pid', null, InputOption::VALUE_OPTIONAL, 'Блокировка по PID', false)
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Время задержки между внутренними циклами для экономии процессора', null)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // прочитать конфигурацию
        $this->readConfigFile($input);

        if ($input->getArgument('action') === 'stop') {
            // Удалим pid
            if (file_exists($this->pidPath)) {
                $this->logger->info("Delete pid file " . $this->pidPath);
                unlink($this->pidPath);
            }

            $this->logger->info("Success stop daemon");
            exit;
        }


        register_shutdown_function([$this, 'shutdownFunction']);

        $poolListRevisionInterval = $this->poolsResolver->revisionInterval();
        $poolListNextRevision = $poolListRevisionInterval;


        $this->executionTime    = 0;
        $startTime              = time();

        $this->pid = getmypid();

        // Проверим что процесс ещё не запущен
        if ($this->lockByPid && $this->isPidFileExists($output)) {
            $this->logger->debug("Daemon process already running, exit now");
            return;
        }

        if ($this->lockByPid) {
            // Подождем, чтобы уже запущенный демон наверняка обнаружил отсутствие lock файла и завершил работу
            sleep(5);
        }

        // Пробуем создать pid-файл
        if ( ! $this->createPidFile($output)) {
            $this->logger->debug("Creating PID file failed, exit now");
            return;
        }

        $this->logger->notice("Daemon started", [
            'pid_path' => $this->pidPath,
            'max_execution_time' => $this->maxExecutionTime,
            'pause_timeout' => $this->usleepDelay
        ]);

        // Зарегаем обработку сигналов
        pcntl_signal(SIGTERM, array($this, "signalHandler"));
        pcntl_signal(SIGINT, array($this, "signalHandler"));

        $this->logger->debug("Backgroud process list creating");

        // Создадим список необходимых фоновых процессов
        $this->poolListRevision();

        $this->logger->debug("Going to start backgroud processes", ['count' => count($this->processList)]);

        // Запустим все фоновые процессы асинхронно
        foreach ($this->processList as $key => $p) {
            $p->start();
            $this->logger->info("start $key command");

            // Запустим команды с задержкой
            // чтобы не все сразу навалились на проц
            usleep($this->usleepDelay);
        }

        $this->logger->debug("Backgroud processes started");

        // Контролируем выполнение дочерних процессов
        while (file_exists($this->pidPath)) {

            // Проверим состояние фоновых процессов,
            // при необходимости перезапустим

            foreach ($this->processList as $key => $p) {
                /* @var $p Process */
                // логировать вывод процесса, при нормальной работе они должны молчать
                $this->checkProcessOutput($p);
                // Перезапустим процесс, если его грохнуло :)
                if (!$p->isRunning()) {
                    // Код возврата 255 говорит об ошибке во время выполнения,
                    // в этом случае повторный запуск должен быть отложен для выяснения причин этой ошибки
                    if (255 !== $p->getExitCode()) {
                        $this->logger->debug("Process $key isn't running, going to start again");
                    } else {
                        // TODO: спорно, может залогировать несколько ошибко подряд
                        $this->logger->critical("Background process $key failed!", [
                            'command' => $p->getCommandLine(),
                            'exit_code' => $p->getExitCode(),
                            'output' => $p->getOutput(),
                            'err_output' => $p->getErrorOutput()
                        ]);
                    }

                    $p->start();
                }

                // Запустим команды с задержкой
                // чтобы не все сразу навалились на проц
                usleep($this->usleepDelay);
            }

            // перезапустим воркеры


            // Проведем ревизию обработчиков
            if ($poolListNextRevision < $this->executionTime) {
                // Проведем ревизию списка
                $this->poolListRevision();
                // Получим время следуюющей ревизиии
                $poolListNextRevision += $poolListRevisionInterval;
            }

            $this->executionTime = time() - $startTime;
            usleep($this->usleepDelay);
        }

        // остановка дочерник процессов в ::shutdown(), вызываемой из ::shutdownFunction()
    }

    public function shutdownFunction() {

        $error = error_get_last();
        if (is_array($error)) {
            $this->logger->error("Error occured", $error);
        }

        if ($this->executionTime > 0) {
            $this->shutdown();
        }
    }

    protected function shutdown()
    {
        $this->logger->notice("Daemon going to stop", ['exec_time' => $this->executionTime, 'pid_file_exist' => file_exists($this->pidPath)]);

        $this->logger->debug("Going to stop background processes", [
            'count' => count($this->processList)
        ]);

        // Грохнем все процессы
        foreach ($this->processList as $p) {
            $p->stop();
        }

        $this->logger->debug("All background processes should be dead");

        // Удалим pid-file
        if (is_writable($this->pidPath)) {
            $unlinkResult = unlink($this->pidPath);
            $this->logger->debug(__FUNCTION__, [
                'line' => __LINE__,
                'unlinkResult' => $unlinkResult
            ]);
        } else {
            $this->logger->debug(__FUNCTION__, [
                'line' => __LINE__,
                'msg' => "PID file isn't writable"
            ]);
        }
    }

    protected function readConfigFile(InputInterface $input)
    {
        $filename = $input->getArgument('config');

        if (!is_readable($filename)) {
            $this->logger->error(__FUNCTION__, ['msg' => "File $filename isn't readable"]);
            throw new \Exception("File $filename isn't readable");
        }

        $yaml_parser = new Parser();
        $this->config = $yaml_parser->parse(file_get_contents($filename));

        $this->mergeConfigParams($input);
    }


    protected function mergeConfigParams(InputInterface $input)
    {
        $this->lockByPid            = $input->getOption('lock-by-pid') ? $input->getOption('lock-by-pid') : $this->config['lock-by-pid'];
        $this->pidPath              = $input->getOption('pid-file') ? $input->getOption('pid-file') : $this->config['pid-file'];
        $this->maxExecutionTime     = $input->getOption('max-execution-time') ? $input->getOption('max-execution-time') : $this->config['max-execution-time'];
        $this->usleepDelay          = ((float) ($input->getOption('sleep') ? $input->getOption('sleep') : $this->config['sleep']));
    }

    protected function checkProcessOutput(Process $p)
    {
        // получать вывод можно только у процессов, которые были запущены
        if (!$p->isStarted()) {
            return;
        }

        $o = $p->getIncrementalOutput();
        $e = $p->getIncrementalErrorOutput();
        if ($o || $e) {
            $p->clearOutput();
            $p->clearErrorOutput();
            $this->logger->warning("Process output", [
                'pid' => $p->getPid(),
                'command' => $p->getCommandLine(),
                'output' => $o,
                'err_output' => $e,
            ]);
        }
    }

    /**
     * Актулизирует список процессов
     */
    protected function poolListRevision()
    {
        // TODO: вместо отключение/включения изменнных делать reset в случае rpc. Если rpc нет, то просто убиваьть
        // TODO: придется различать изменные

        $pools = $this->poolsResolver->getPools();

        $this->logger->info('pool-list', $pools);

        $processedPoolKeys = array_keys($this->processList);

        $this->logger->info('pool-current-list', $processedPoolKeys);

        foreach ($pools as $pool) {
            $processedPoolKeys = array_filter($processedPoolKeys, function($value) use ($pool) {
                return $value != $pool->getKey();
            });
            // Если есть в списке, то идем дальше
            if (isset( $this->processList[$pool->getKey()])) {
                continue;
            }

            $this->logger->debug("Iterating {$pool->getKey()} pool", ['pool' => $pool]);

            try {
                $command = sprintf(__DIR__ . '/../Utils/rr serve -c %s', $pool->getConfigPath());

                $process = new BackgroundProcess($command);
                $process->setPool($pool);

                $this->processList[$pool->getKey()] = $process;
                $this->logger->debug("Process {$pool->getKey()} appended to list");
            } catch (\Exception $e) {
                $this->logger->critical("Exception caught during process creating", [
                    'task' => $pool,
                    'exception' => $e
                ]);
            }
        }

        // Остановим те, которых нет в новом списке
        foreach ($processedPoolKeys as $poolKey) {
            $this->logger->debug("Process {$poolKey} stoped");
            $this->processList[$poolKey]->stop();
            unset($this->processList[$poolKey]);
        }
    }

    protected function isPidFileExists(OutputInterface $output)
    {
        $this->logger->debug(__FUNCTION__, [
            'line' => __LINE__,
            'pidPath' => $this->pidPath,
        ]);

        $pidFileResetFunction = function($pidPath) {
            if ( ! unlink($pidPath)) {
                $this->logger->error(
                    'Cannot unlink PID file. Please remove it by hand and check permissions.',
                    [
                        'file' => $pidPath
                    ]
                );
                return true;
            } else {

                $this->logger->debug(__FUNCTION__, [
                    'line' => __LINE__,
                    'msg' => 'Old PID file was unlinked'
                ]);

                return false;
            }
        };

        if (is_readable($this->pidPath)) {

            $pid = (int) file_get_contents($this->pidPath);

            $this->logger->debug(__FUNCTION__, [
                'line' => __LINE__,
                'pid' => $pid
            ]);

            if (file_exists("/proc/$pid/cmdline")) {
                $myCmdLine      = file_get_contents("/proc/{$this->pid}/cmdline");
                $runningCmdLine = file_get_contents("/proc/$pid/cmdline");

                if ($myCmdLine == $runningCmdLine) {

                    $this->logger->debug(__FUNCTION__, [
                        'line' => __LINE__,
                    ]);
                    return true;

                } else {

                    $this->logger->debug(__FUNCTION__, [
                        'line' => __LINE__,
                        'msg' => 'Another command running with recorded PID',
                        'myCmdLine' => $myCmdLine,
                        'runningCmdLine' => $runningCmdLine
                    ]);
                    return $pidFileResetFunction($this->pidPath, $this->logger);

                }
            } else {
                // Процесс не запущен, но файл не удален
                // Грохнем файл процесса

                $this->logger->debug(__FUNCTION__, [
                    'line' => __LINE__,
                    'msg' => 'No running process with recorded PID'
                ]);
                return $pidFileResetFunction($this->pidPath, $this->logger);
            }

        } else {

            $this->logger->debug(__FUNCTION__, [
                'line' => __LINE__,
                'is_readable' => false,
                'file_exists' => file_exists($this->pidPath)
            ]);

        }

        return false;
    }

    protected function createPidFile(OutputInterface $output)
    {
        $this->logger->debug(__FUNCTION__);

        $myPid = sprintf('%d', $this->pid);

        $this->logger->debug(__FUNCTION__, [
            'line' => __LINE__,
            'myPid' => $myPid
        ]);

        if ($file = fopen($this->pidPath, 'w')) {
            $writeResult = fwrite($file, $myPid, strlen($myPid));
            $closeResult = fclose($file);

            $this->logger->debug(__FUNCTION__, [
                'line' => __LINE__,
                'writeResult' => $writeResult,
                'closeResult' => $closeResult
            ]);
        } else {
            $this->logger->error(
                'Unable to create PID file',
                [
                    'file' => $this->pidPath
                ]
            );
            return false;
        }

        $chmodResult = chmod($this->pidPath, 0664);

        $this->logger->debug(__FUNCTION__, [
            'line' => __LINE__,
            'chmodResult' => $chmodResult
        ]);

        return true;
    }

    public function signalHandler($signo, $pid = null, $status = null)
    {
        switch ($signo) {

            case SIGTERM:
            case SIGINT:
            case SIGKILL:
                if (file_exists($this->pidPath)) {
                    unlink($this->pidPath);
                }
                break;

            default:
                break;
        }
    }

}
