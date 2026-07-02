<?php

namespace app\command;

use app\service\RabbitmqService;
use app\service\RedisService;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Cache;

class RabbitmqDelStockConsume extends Command
{
    protected function configure()
    {
        $this->setName('mq:del_stock_consume')->setDescription('从Redis删除商品库存消费者');
    }


    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \AMQPConnectionException
     * @throws \ErrorException
     * @author foxme
     * @date 2026/6/18 14:52
     * Description: 库存删除处理消费逻辑
     */
    protected function execute(Input $input, Output $output)
    {
        set_time_limit(0); // 不限制脚本执行时间
        // 记录开始时间
        $time_start = microtime(true);
        echo "\n【" . date('Y-m-d H:i:s') . "】批量删除商品库存写入任务【开始】执行" . PHP_EOL;
        $queue_name = RabbitmqService::SYNC_DEL_STOCK_PUBLISHER;
        $is_produce = RabbitmqService::ZERO;
        RabbitmqService::pop($queue_name, function ($consume_msg) use ($queue_name, $is_produce, $input, $output) {
            try {
                $data = json_decode($consume_msg->body, true);
                $goodsId = $data['goods_id'];
                $stockKey = empty($data['redis_key']) ? (RedisService::$flushAllStockKey . '&&' . $goodsId) : $data['redis_key'];
                $userId = empty($data['user_id']) ? 1 : $data['user_id'];//用户ID
                $stock = empty($data['true_stock']) ? RedisService::getStock($stockKey) : $data['true_stock'];//库存
                $result = RedisService::delStock($stockKey);//直接初始化存在的可用库存数据
                // 兼容 phpredis (bool) 和 predis (string "OK")，默认这里返回true
                if (($result === true) || ($result === 'OK') || is_int($result)) {
                    $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 1, 'key' => $stockKey, 'total_num' => $stock, 'success_num' => $stock, 'val' => $stock, 'operate_type' => 4]);//直接删除成功了
                    //更新仓库表可用库存为0
                    RedisService::updateStock($stockKey, $goodsId);
                    $msg = "商品ID【" . $goodsId . "】同步删除商品库存消费成功。。。。。。。。" . PHP_EOL;
                    RabbitmqService::insertData($is_produce, $queue_name, '同步删除商品库存消费成功' . PHP_EOL, $msg);
                    echo $msg;
                } else {
                    $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 2, 'key' => $stockKey, 'total_num' => $stock, 'fail_num' => $stock, 'val' => $stock, 'operate_type' => 4]);//直接删除失败了
                    $msg = "商品ID【" . $goodsId . "】同步删除商品库存《《失败》》" . PHP_EOL;
                    RabbitmqService::insertData($is_produce, $queue_name, '同步删除商品库存失败' . PHP_EOL, $msg);
                    echo $msg;
                }
            } catch (\Exception $e) {
                $msg = "同步删除商品库存错误，异常信息是：【" . $e->getMessage() . "】" . PHP_EOL;
                RabbitmqService::insertData($is_produce, $queue_name, '同步删除商品库存错误' . PHP_EOL, $msg);
                echo $msg;
            }
            $consume_msg->ack();//手动确认
        });


        echo "\n【" . date('Y-m-d H:i:s') . "】批量删除商品库存写入任务【结束】执行" . PHP_EOL;
        // 记录结束时间
        $time_end = microtime(true);
        // 计算并打印执行时间
        $execution_time = ($time_end - $time_start);
        echo "\n【" . date('Y-m-d H:i:s') . "】脚本执行时间总共：【" . $execution_time . "】 秒。";
    }

}
