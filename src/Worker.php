<?php

namespace icy8\process;
//declare(ticks=1);

use icy8\EventBus\Emitter;

class Worker
{
    protected        $pid;
    protected        $event;
    protected        $startFile;
    protected static $masterPid; // 主进程id
    protected        $process;   // 工作进程
    protected        $processes  = []; // 按照业务分配进程
    public           $title      = 'icy8-worker';// 进程名称
    protected        $workerPids = []; // 子进程pid
    public           $total      = 1;  // 需要运行的进程数 0代表无限个 进程会一直运行
    public           $max        = 0;  // 允许同时打开的进程数 0不限制

    public function __construct()
    {
        // 检查环境
        $this->isSupported();
        $this->event     = new Emitter(); // 事件发布
        self::$masterPid = getmypid();
        $this->pid       = getmypid();
        $backtrace       = \debug_backtrace();
        $this->startFile = $backtrace[\count($backtrace) - 1]['file'];
    }

    public function run($process = null)
    {
        $this->init($process);
        $i = 1;
        while ($this->total === 0 || $i <= $this->total) {
            if ($this->total > 0) $i++;
            // 控制最大运行进程数
            $this->waitingWorkerReleaseIfExceed();
            // 打开一个进程
            $this->fork();
        }
        register_shutdown_function([$this, 'stop']);
        // 主进程保持运行
        while (!empty($this->workerPids)) {
            $pid = pcntl_wait($status);
            $this->whenChildWorkerFinish($pid, $status);
        }
    }

    /**
     * 初始化
     * @param null $process
     * @throws \Exception
     */
    public function init($process = null)
    {
        $this->bindProcess($process);
        // 注册监听终止进程信号
        pcntl_signal(SIGUSR1, [$this, "sigHandler"]);
        // 子进程退出信号
        pcntl_signal(SIGCHLD, [$this, 'sigHandler']);
        // 终止进程
        pcntl_signal(SIGINT, [$this, 'sigHandler']);
        // kill信号
        pcntl_signal(SIGTERM, [$this, 'sigHandler']);
        // 异步分发信号
        pcntl_async_signals(true);
    }

    /**
     * 获取业务逻辑
     * @return mixed
     */
    public function fetchProcess()
    {
        if (!empty($this->processes)) {
            return array_shift($this->processes);
        }
        return $this->process;
    }

    /**
     * 绑定工作进程
     * @param $process
     * @return $this
     * @throws \Exception
     */
    public function bindProcess($process)
    {
        if (!$process) {
            throw new \Exception('invalid process');
        } else if (is_array($process)) {
            $this->processes = $process;
            $this->total     = count($process);
        } else {
            $this->process = $process;
        }
        return $this;
    }

    /**
     * 终止当前进程
     */
    public function stop()
    {
        $this->event->trigger('worker_stoped', $this);
        if ($this->isMaster()) {
            // 父进程退出要终止所有子进程
            $this->killAll();
        }
        exit(1);
    }

    /**
     * 杀死所有子进程
     * @return bool
     * @throws \Exception
     */
    public function killAll()
    {
        if (!$this->isMaster()) {
            throw new \Exception('only run in master worker');
        }
        foreach ($this->workerPids as $pid) {
            if ($pid === $this->getMasterPid()) {
                // 主进程不能被杀死
                continue;
            }
            posix_kill($pid, SIGUSR1);
        }
        return true;
    }

    /**
     * 新建一个子进程
     */
    public function fork()
    {
        $this->event->trigger('worker_forking', $this);
        $process = $this->fetchProcess();
        if (!$process) {
            throw new \Exception('have no process to run');
        }
        $pid = pcntl_fork();
        if ($pid === 0) {
            // 子进程
            $this->workerPids = [];
            $this->pid        = getmypid();
            $this->setProcessTitle($this->title . ' ' . $this->startFile);
            call_user_func_array($process, [$this]);
            exit(0);
        } else if ($pid < 0) {
            $this->event->trigger('worker_fork_fail', $this);
        } else {
            $this->setProcessTitle($this->title . ' master ' . $this->startFile);
            // 父进程逻辑
            $this->workerPids[$pid] = $pid;
            $this->event->trigger('worker_forked', $this, $pid);
        }
    }

    /**
     * 如果超出最大进程限制，则等待子进程退出
     */
    public function waitingWorkerReleaseIfExceed()
    {
        if ($this->max > 0 && count($this->workerPids) >= $this->max) {
            $pid = pcntl_wait($status);
            $this->whenChildWorkerFinish($pid, $status);
        }
    }

    /**
     * 子进程结束时
     * @param $pid
     * @param $status
     */
    public function whenChildWorkerFinish($pid, $status)
    {
        if (isset($this->workerPids[$pid])) {
            unset($this->workerPids[$pid]);
            $this->event->trigger('worker_finish', $this, $pid, $status);
        }
    }

    /**
     * 捕获进程信号
     * @param $signo
     */
    public function sigHandler($signo)
    {
        switch ($signo) {
            case SIGUSR1:
                $this->stop();
                break;
            case SIGCHLD:
                break;
            case SIGINT:
                $this->stop();
                break;
            case SIGTERM:
                $this->stop();
                break;
            default:
        }
    }

    /**
     * 检查环境
     * @throws \Exception
     */
    public function isSupported()
    {
        if (PHP_SAPI != 'cli') {
            throw new \Exception('allow in cli mode');
        }
        $extensions = ['pcntl', 'posix'];
        $functions  = ['pcntl_signal', 'pcntl_fork', 'getmypid', 'posix_kill'];
        foreach ($extensions as $extension) {
            if (!extension_loaded($extension)) {
                throw new \Exception("need [{$extension}] extension");
            }
        }
        foreach ($functions as $function) {
            if (!function_exists($function)) {
                throw new \Exception("function [{$function}] not exists");
            }
        }
    }

    protected function setProcessTitle($title)
    {
        if (!empty($title) && function_exists('cli_set_process_title')) {
            cli_set_process_title($title);
        }
    }

    /**
     * 获取master进程的进程id
     * @return mixed
     */
    public function getMasterPid()
    {
        return self::$masterPid;
    }

    /**
     * 是否为master进程
     * @return bool
     */
    public function isMaster()
    {
        return getmypid() === self::$masterPid;
    }

    public function workerCount()
    {
        return count($this->workerPids);
    }

    /**
     * 事件监听
     * @param $name
     * @param $event
     * @return $this
     */
    public function on($name, $event)
    {
        $this->event->on($name, $event);
        return $this;
    }

    /**
     * 一次性事件
     * @param $name
     * @param $event
     * @return $this
     */
    public function once($name, $event)
    {
        $this->event->on($name, $event, true);
        return $this;
    }

    /**
     * 卸载一个事件
     * @param $name
     * @return $this
     */
    public function off($name)
    {
        $this->event->on($name);
        return $this;
    }
}
