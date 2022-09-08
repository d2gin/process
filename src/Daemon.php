<?php

namespace icy8\process;

use icy8\EventBus\Emitter;

class  Daemon
{
    const stdin_redirect  = 2;
    const stdout_redirect = 4;
    const stderr_redirect = 8;
    protected $pid;
    protected $process;
    protected $event;
    public    $pidFile     = '';
    protected $initialized = false;

    public function __construct()
    {
        if (PHP_SAPI != 'cli') {
            throw new \Exception('allow in cli mode');
        }
        $this->event = new Emitter();
    }

    /**
     * 初始化
     * @return $this
     */
    public function init()
    {
        if ($this->initialized) return $this;
        if (empty($this->pidFile)) {
            $backtrace     = \debug_backtrace();
            $_startFile    = $backtrace[\count($backtrace) - 1]['file'];
            $unique_prefix = \str_replace('/', '_', $_startFile);
            $this->pidFile = __DIR__ . '/../' . $unique_prefix . '.pid';
        }
        $this->initialized = true;
        return $this;
    }

    public function run()
    {
        $opt = array_fill_keys(array_keys(getopt("", ["start", "stop", "status", "reload"])), 1);
        $this->init();
        if ($opt['start']) {
            $this->start(...func_get_args());
        } else if ($opt['stop']) {
            $this->stop();
        } else if ($opt['status']) {
            $this->status();
        } else if ($opt['reload']) {
            $this->reload();
        }
    }

    public function start($process = null)
    {
        if (is_file($this->pidFile)) {
            throw new \Exception("process [" . file_get_contents($this->pidFile) . '] is running');
        } else if (!$this->process && $process) {
            $this->process = $process;
        }
        // 开始制作守护进程
        $this->daemon();
        // 脚本运行结束
        register_shutdown_function(function () {
            if ($this->pid === getmypid()) @unlink($this->pidFile);
        });
        // 捕获退出信号
        pcntl_signal(SIGTERM, [$this, "sigHandler"]);
        // 异步分发信号
        pcntl_async_signals(true);
        $this->event->trigger('started', $this);
        // 不强制配置业务代码
        if($this->process) {
            call_user_func_array($this->process, [$this]);
        }
    }

    public function stop()
    {
        $this->event->trigger('stoping', $this);
        $pid = @file_get_contents($this->pidFile);
        if ($pid) {
            posix_kill($pid, SIGTERM);
            @unlink($this->pidFile);
        }
        $this->event->trigger('stoped', $this);
    }

    public function reload()
    {
        $this->stop();
        $this->start();
    }

    public function status()
    {
        if (is_file($this->pidFile)) {
            echo "process is running" . PHP_EOL;
        } else echo "process is not running" . PHP_EOL;
    }

    protected function daemon()
    {
        $pid = pcntl_fork();
        if ($pid > 0) {
            // 退出父进程
            exit;
        } else if ($pid < 0) {
            throw new \Exception('err');
        }
        // 子进程申请组长id
        if (posix_setsid() === -1) die;
        chdir('/');
        umask(0);
        // 第二次fork
        $pid = pcntl_fork();
        if ($pid > 0) {
            // 继续退出子进程
            @file_put_contents($this->pidFile, $pid);
            exit;
        } else if ($pid < 0) {
            throw new \Exception('err2');
        }
        $this->pid = getmypid();
        // 保留孙子进程
        // 重定向标准输出
        $this->stdRedirect();
    }

    protected function sigHandler($sig)
    {
        switch ($sig) {
            case SIGTERM:
                @unlink($this->pidFile);
                exit(1);
                break;
            default:
        }
    }

    /**
     * 标准输出重定向
     */
    protected function stdRedirect($toclose = self::stdin_redirect | self::stdout_redirect | self::stderr_redirect)
    {
        if ($toclose & self::stdin_redirect) {
            fclose(STDIN); //Resource id #1
            // 重新占位资源
            global $stdin;
            $stdin = fopen('/dev/null', 'r');
        }
        if ($toclose & self::stdout_redirect) {
            fclose(STDOUT);//Resource id #2
            // 重新占位资源
            global $stdout;
            $stdout = fopen('/dev/null', 'wb');
        }
        if ($toclose & self::stderr_redirect) {
            fclose(STDERR);//Resource id #3
            // 重新占位资源
            global $stderr;
            $stderr = fopen('/dev/null', 'wb');
        }
    }

    public function getPid()
    {
        return $this->pid;
    }

    public function on($name, $event)
    {
        $this->event->on($name, $event);
        return $this;
    }
}
