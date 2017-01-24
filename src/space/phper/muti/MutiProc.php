<?php
namespace Src\Space\Phper\Muti;
if (! defined('PHPER_SPACE_MUTIPROC_MARK')) exit('No direct script access allowed');

use Src\Space\Phper\Muti\Lib\Log;

/**
 * 多进程类
 * 
 * 基于共享内存进行进程间通信，实现的功能包括：
 * 1)工作进程超时自动退出
 * 2)worker平滑重启
 * 3)通过对本地文件flock实现读锁，解决共享内存的可重复读&&read-then-write问题。
 * 
 * 使用时候，只需继承MutiProc类，并覆盖_work()方法
 * 
 * @author phper.space
 *
 */
abstract class MutiProc
{

    /**
     * 最大进程数
     * @var int
     */
    protected $_maxProcNum;

    /**
     * 子进程最大执行时间（ms）
     * -1 表示不限。
     * @var int
     */
    protected $_maxExcuteTime = -1;
    
    /**
     * 共享内存id
     * @var int
     */
    protected $_shmid;

    /**
     * 共享字节（bytes）数，10k
     * @var int
     */
    protected static $_SHMOP_SIZE = 10240;

    /**
     * 锁文件的路径
     * @var int
     */
    protected static $_FILE_PATH_LOCK = '';

    /**
     * 标识worker身份
     * @var int
     */
    protected $_isWorker = 0;

    /**
     * 构造方法
     */
    public function __construct()
    {
        global $phper_space_muti_config;
        
        // 从配置文件初始化 
        $this->_maxProcNum      = $phper_space_muti_config['muti_proc']['max_proc_num'];
        $this->_maxExcuteTime   = $phper_space_muti_config['muti_proc']['max_excute_time'];
        
        // 用于上锁的文件
        self::$_FILE_PATH_LOCK  = PHPER_SPACE_MUTIPROC_PATH . "/temp/file.lock";
    
        // 分配一块共享内存
        $this->_shmid           = shmop_open(ftok(__FILE__, 'p'), "c", 0644, self::$_SHMOP_SIZE);
        if (! $this->_shmid) {
            $this->_logMsg('Couldnt create shared memory segment.', 'FATAL');
            exit;
        }
    }

    /**
     * 析构方法
     */
    public function __destruct() 
    {
        
    }
    
    /**
     * 启动
     */
    public function start()
    {
    
        $this->_logMsg('master start', 'DEBUG');
    
        // 初始化共享内存
        $currentJobs = array(
            'update_version' => time(),
            'should_stop' => 0,
            'proc_list' => array()
        );
        $currentJobs = $this->_shmopWrite($currentJobs);
    
        // 死循环
        while (TRUE) {
    
            // 上锁
            $lock = $this->_getLock(FALSE);
            if (! $lock) {
                continue;
            }
    
            // 取共享内存数据
            $currentJobs = $this->_shmopRead();
            $lock && $this->_freeLock($lock);
    
            // 当前worker数量
            $nowCount = count($currentJobs['proc_list']);
            $this->_logMsg("master while: proc num is {$nowCount}.", 'DEBUG');
    
            // 检测到退出指令
            if ($currentJobs['should_stop']) {
    
                // 静待worker都退出
                if ($nowCount > 0) {
                    $this->_logMsg('master stop: waiting worker to exit.', 'DEBUG');
                    sleep(1);
                    continue;
                }
    
                // 不再工作
                $this->_logMsg('master stop: break while', 'DEBUG');
                break;
            }
    
            // worker足够，不需要fork
            if ($nowCount >= $this->_maxProcNum) {
                $this->_logMsg('master sleep: enough worker num.', 'DEBUG');
                sleep(1);
                continue;
            }
    
            // fork
            $jobID = self::_generateAJobID();
            $this->_launchJob($jobID);
    
        }
    
        // 主进程退出
        $this->_logMsg('master exit!!!', 'NOTICE');
        $this->_clearShm();
        exit();
    }

    /**
     * 终止
     */
    public function stop()
    {
        $lock = $this->_getLock();
    
        // 取共享内存数据
        $currentJobs = $this->_shmopRead();
        // 重设版本号
        $currentJobs['update_version'] = time();
        // 设stop参数
        $currentJobs['should_stop'] = 1;
        // 回存
        $this->_shmopWrite($currentJobs);
    
        $this->_freeLock($lock);
    }
    
    /**
     * 重启
     */
    public function restart()
    {
        $this->stop();
        $this->start();
    }
    
    /**
     * 重启worker进程
     * 当work方法有更新时候，面临如何平滑重启worker的问题。
     * ！！！注意，如果不平滑的话，直接kill会导致work方法执行到一半意外退出。如果有重要数据就惨了。
     */
    public function restartWorker()
    {
        $lock = $this->_getLock();
    
        // 取共享内存数据
        $currentJobs = $this->_shmopRead();
        // 重设版本号
        $currentJobs['update_version'] = time();
        // 回存
        $this->_shmopWrite($currentJobs);
    
        $this->_freeLock($lock);
    }
    
    /**
     * 日志
     *
     * @param string $msg
     * @param string $level
     */
    protected function _logMsg($msg, $level)
    {
        $log = Log::getInstance();
        $log->logMsg($msg, $level);
    }
    
    /**
     * 清理共享内存
     */
    protected function _clearShm()
    {
        if (! empty($this->_shmid)) {
            shmop_delete($this->_shmid);
            shmop_close($this->_shmid);
        }
        $this->_logMsg("clear shm done", 'NOTICE');
    }
    
    /**
     * 获取锁
     * 不阻塞，会重试
     * @param bool $failExist
     */
    protected function _getLock($failExist = TRUE)
    {
        $fp = fopen(self::$_FILE_PATH_LOCK, "r+");
    
        $wouldBlock = 0;
        for ($i = 0; $i < 30; $i ++) {
            // 上锁
            $locked = flock($fp, LOCK_EX|LOCK_NB, $wouldBlock);
            if ($locked) {
                return $fp;
            }
            // 上锁失败，30微妙后重试
            $this->_logMsg(getmypid() . " lock failed ", 'NOTICE');
            usleep(30);
            continue;
        }
    
        fclose($fp);
        
        if ($failExist) {
            $this->_logMsg(getmypid() . " exited without lock", 'FATAL');
            $workerExitStatus = 0;
            exit($workerExitStatus);
        }
    
    }

    /**
     * 释放锁
     * @param obj $fp
     */
    protected function _freeLock($fp)
    {
        // 释放锁
        flock($fp, LOCK_UN);
        // 关闭句柄
        fclose($fp);
    }
    
    /**
     * 写共享内存
     * @param mixed $value
     */
    protected function _shmopWrite($value)
    {
        $value = serialize($value);
        $value = "$value\0";
        shmop_write($this->_shmid, $value, 0);
    }
    
    /**
     * 读共享内存
     */
    protected function _shmopRead()
    {
        $value = shmop_read($this->_shmid, 0, self::$_SHMOP_SIZE);
        $i = strpos($value, "\0");
        if ($i !== FALSE) {
            $value = substr($value, 0, $i);
        }
        return unserialize($value);
    }
    
    /**
     * fork一个worker
     * 
     * @param string $jobID
     */
    protected function _launchJob($jobID)
    {
        $pid = pcntl_fork();
        
        // 父进程和子进程都会执行下面代码
        switch ($pid) {
            case - 1: 
                // 错误处理：创建子进程失败时返回-1.
                $this->_logMsg('PCNTL functions not available on this PHP installation', 'FATAL');
                $this->_clearShm();
                exit();
            case 0: 
                // 子进程得到的$pid为0, 所以这里是子进程执行的逻辑。
                $this->_isWorker = 1;
                while (! $this->_workerShouldExit($jobID)) {
                    $this->_work($jobID);
                }
                /* 指定错误码退出!!!注意此处必须跟着exit。
                 * 原因是fork的本质会复制一份父进程的地址空间，子进程会接着往下执行代码。
                 * 子进程再fork子进程，cpu会被玩死。
                 */
                $this->_workerExit($jobID);
                break;
            default: 
                // 父进程会得到子进程号，所以这里是父进程执行的逻辑
                $lock = $this->_getLock();
                $currentJobs = $this->_shmopRead();
                $currentJobs['proc_list'][$jobID] = array(
                    'pid' => $pid,
                    'fork_time' => microtime(TRUE),
                    'current_version' => $currentJobs['update_version'],
                );
                $this->_shmopWrite($currentJobs);
                $this->_freeLock($lock);
                
                $this->_logMsg("fork success pid:{$pid}", 'NOTICE');
                // 等待子进程中断，防止子进程成为僵尸进程。TODO pcntl_wait会造成阻塞，不是我想要的。
                // pcntl_wait($status);
                break;
        }
    }

    /**
     * 构造一个jobid
     */
    protected static function _generateAJobID()
    {
        list ($usec, $sec) = explode(" ", microtime());
        $microTime = $sec . substr($usec . '', 2, - 2);
        return $microTime . rand(10000, 99999);
    }

    /**
     * work
     * 实际worker子进程执行的方法，此处为抽象方法，需要子类实现
     * 
     * @param string $jobID
     */
    abstract protected function _work($jobID);

    /**
     * 判断worker是否应该退出
     * 
     * @param string $jobID
     * @return bool
     */
    protected function _workerShouldExit($jobID)
    {
        $currentJobs = $this->_shmopRead();
        
        // 注意父进程可能后执行，要先判断，不然会造成index offset
        if (empty($currentJobs['proc_list'][$jobID])) {
            return FALSE;
        }
        
        // timeout 检查， -1 表示不限
        if (- 1 != $this->_maxExcuteTime) {
            $passedMs = (int) (1000 * (microtime(TRUE) - $currentJobs['proc_list'][$jobID]['fork_time']));
            if ($passedMs > $this->_maxExcuteTime) {
                return TRUE;
            }
        }
        
        // 版本检测
        if ($currentJobs['proc_list'][$jobID]['current_version'] != $currentJobs['update_version']) {
            return TRUE;
        }
        
        return FALSE;
    }

    /**
     * worker 退出
     * @param string $jobID
     */
    protected function _workerExit($jobID)
    {
        // 使用这种方式，还是会存在连续获取锁失败的问题！！！TODO
        $lock = $this->_getLock();
        $currentJobs = $this->_shmopRead();
        unset($currentJobs['proc_list'][$jobID]);
        $this->_shmopWrite($currentJobs);
        $this->_freeLock($lock);
        
        $this->_logMsg(getmypid() . " is exiting ", 'NOTICE');
        $workerExitStatus = 0;
        exit($workerExitStatus);
    }

}




