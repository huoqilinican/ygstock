<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;
use think\facade\Db;

class SwooleServer extends Command
{
    protected function configure()
    {
        $this->setName('swoole:server')->setDescription('swoole服务端');
    }


    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \think\db\exception\DbException
     * @author foxme
     * @date 2026/6/17 15:15
     * Description: swoole服务端
     */
    protected function execute(Input $input, Output $output)
    {
        //创建Server对象，监听 127.0.0.1:9501 端口。
        $server = new Swoole\Server('127.0.0.1', 9501);


        //监听连接进入事件。
        $server->on('Connect', function ($server, $fd) {
            echo "Client: Connect.\n";
        });


        //监听数据接收事件。
        $server->on('Receive', function ($server, $fd, $reactor_id, $data) {
            $server->send($fd, "Server: {$data}");
        });

        //监听连接关闭事件。
        $server->on('Close', function ($server, $fd) {
            echo "Client: Close.\n";
        });

       //启动服务器
        $server->start();

    }

}
