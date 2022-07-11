# queue

#### 介绍
php多进程和守护进程的执行器，只支持在命令行运行。
由于项目使用了`pcntl_async_signals`函数做信号分发，所以要求你的php版本>=7.1。

进程控制流程

```
 send SIGUSR1 ->    /process1    \
  ^                / process2     \
master  ------------ process3      SIGUSR1 exit(1)
  ^                \ process4     /
  |                 \process5    /
  |send SIGTERM
  |
 kill
```

 #### 软件架构
 1. `php >= 7.1`

 2. pcntl扩展

 3. posix扩展

 #### 安装教程

 ```shell
composer require icy8/process
 ```

 #### 使用说明

 1. 多进程模型
 如果master进程被kill，子进程也会被强制退出。
 
 ```php
 <?php
 use icy8\process\Worker;
 $handle        = new Worker();
 $handle->total = 2;// 需要运行的进程数
 // $handle->max = 10;// 允许同时打开的进程数 0不限制
 $handle->run(function ($worker) {
     // 闭包里是你的业务代码    
     while (1) {
         var_dump(time());
         sleep(1);
     }
 });
 ```
 2. 守护进程模型
 守护进程在制作过程中已经将标准输出重定向到`/dev/null`。
执行：`php daemon.php --start|stop|status`
 
 ```php
 <?php
 use icy8\process\Daemon;
 $daemon        = new Daemon();
 $daemon->run(function () {    
     sleep(10);        
 });
 ```
 3. 多进程结合守护进程使用
 这种模式下，通常多进程模型中的master进程就是当前的守护进程。
 
 ```php
 <?php
 use icy8\process\Worker;
 use icy8\process\Daemon;
 
 $daemon        = new Daemon();
 //$daemon->pidFile = '/www/test.pid';// 允许自定义一个存放进程id的文件路径，一定要设置绝对路径
 $daemon->run(function () {
     // 放到守护进程运行
     $handle        = new Worker();
     $handle->total = 2;
     $handle->run(function ($worker) {
         while (1) {
             var_dump(time());
             sleep(1);
         }
     });
 });
 ```
 
 