<?php
if (! defined('PHPER_SPACE_MUTIPROC_MARK')) exit('No direct script access allowed');

/**
 * 配置数组 
 */
$phper_space_muti_config                                     = array();

/**
 * 日志相关配置
 * 建议配置:
 * NONE     如果你不希望记日志，可以配成NONE
 * NOTICE   如果你是正式环境，建议配置成NOTICE
 * DEBUG    如果你是测试环境，建议配置成DEBUG
 */
$phper_space_muti_config['log']['log_level']                 = 'DEBUG';

/**
 * 日志文件的路径
 * 如果你不打算记日志，请置空
 */
$phper_space_muti_config['log']['log_path']                  = PHPER_SPACE_MUTIPROC_PATH . '/log/phper_space_muti.log';

/**
 * 工作的子进程数量
 */
$phper_space_muti_config['muti_proc']['max_proc_num']        = 4;

/**
 * 子进程最大执行时间
 */
$phper_space_muti_config['muti_proc']['max_excute_time']     = 3000 * 10;

/* End of file conf.php */
/* Location: ./conf/conf.php */