<?php
namespace Test;
if (! defined('PHPER_SPACE_MUTIPROC_MARK')) exit('No direct script access allowed');

require_once __DIR__ . '/../autoload.php';

use Src\Space\Phper\Muti\MutiProc;

/**
 * 通过继承实现自己的多进程类
 * 
 * @author phper.space
 *
 */
class MyMutiProc extends MutiProc
{

    /**
     * 实现父类的work方法
     * 
     * @param string $jobID
     */
    protected function _work($jobID)
    {
        $this->_logMsg(getmypid() . " is working ", 'NOTICE');
        sleep(1);
    }


}




