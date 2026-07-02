<?php

namespace app\command;

use Predis\Client;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Cache;
use think\facade\Db;
use Swoole\Coroutine\Redis;

class FlushStock extends Command
{
    protected function configure()
    {
        $this->setName('flush:stock')
            ->setDescription('预热商品库存到Redis')
            ->addArgument('goods_id', Argument::OPTIONAL, "商品ID")
            ->addArgument('stock', Argument::OPTIONAL, "库存数量");
    }


    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @author foxme
     * @date 2026/6/16 18:56
     * Description: 库存预热处理逻辑
     */
    protected function execute(Input $input, Output $output)
    {
        $goodsId = $input->getArgument('goods_id');
        $stock = $input->getArgument('stock');
//        if ($input->hasOption('goods_id')) {
//            $goods_id = PHP_EOL . 'goodsId ' . $input->getOption('goods_id');
//        } else {
//            $goods_id = '';
//        }

        if(empty($goodsId) && empty($stock)){
            //全量同步脚本时刷新商品goodsId和对应的库存stock到redis，为了快速，我们异步通过rabbitmq去发布并消费

        }else {
            $redis = new Client([
                'scheme' => 'tcp',
                'host'   => '127.0.0.1',
                'port'   => 9508,
            ]);
            // 1. 设置库存
            $stockKey = "seckill:stock:{$goodsId}";
            $redis->set($stockKey, $stock);
            $output->writeln("秒杀预热成功: goods_id={$goodsId}, stock={$stock}");
        }
    }

}
