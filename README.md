# php多进程任务管理工具   

## 功能    
1.实现多进程模型      
2.支持worker平滑重启    
3.可配置worker超时退出    

## 原理   
1.master负责监控和保证固定worker数量  
2.基于共享内存进行进程间通信    
3.通过对本地文件flock实现读锁，解决共享内存的可重复读&&read-then-write问题。
    
# 使用    
只需继承类，并覆盖_work()方法，然后通过命令行启动继承类的start方法即可。    
1、必须要先引入autoload.php，如下：  

	require_once '../autoload.php';  
	
2、引入相关的MutiProc类及命名空间，如下：  
	
	use Src\Space\Phper\Muti\MutiProc;  
	
3、继承MutiProc类，并实现_work($jobID)方法：  

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
4、启动    

	$proc = new MyMutiProc();
    $proc->start();


