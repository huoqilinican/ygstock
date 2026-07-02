<?php

namespace app\command;

use Predis\Client;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;
use think\facade\Db;

class SyncStock extends Command
{
    protected function configure()
    {
        $this->setName('sync:stock')->setDescription('同步Redis库存到数据库');
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \think\db\exception\DbException
     * @author foxme
     * @date 2026/6/16 18:58
     * Description: 同步Redis库存到数据库逻辑
     */
    protected function execute(Input $input, Output $output)
    {
        $redis = new Client([
            'scheme' => 'tcp',
            'host'   => '127.0.0.1',
            'port'   => 9508,
        ]);

        // 获取所有秒杀商品的key
        $keys = $redis->keys('seckill:stock:*');

        foreach ($keys as $key) {
            preg_match('/seckill:stock:(\d+)/', $key, $matches);
            $goodsId = $matches[1] ?? 0;

            if (!$goodsId) continue;

            $redisStock = $redis->get($key);
            $dbStock = Db::name('goods')->where('id', $goodsId)->value('stock');

            if ($redisStock !== null && $redisStock != $dbStock) {
                // 同步库存
                Db::name('goods')
                    ->where('id', $goodsId)
                    ->update(['stock' => $redisStock]);

                $output->writeln("同步库存: goods_id={$goodsId}, stock={$redisStock}");
            }
        }

        $output->writeln("库存同步完成");
    }

}
