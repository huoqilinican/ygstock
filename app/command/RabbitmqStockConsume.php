<?php

namespace app\command;

use app\service\RabbitmqService;
use app\service\RedisService;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Cache;

class RabbitmqStockConsume extends Command
{
    protected function configure()
    {
        $this->setName('mq:stock_consume')->setDescription('设置商品库存到Redis消费者');
    }


    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \AMQPConnectionException
     * @throws \ErrorException
     * @author foxme
     * @date 2026/6/18 14:52
     * Description: 库存设置处理消费逻辑
     */
    protected function execute(Input $input, Output $output)
    {
        set_time_limit(0); // 不限制脚本执行时间
        // 记录开始时间
        $time_start = microtime(true);
        echo "\n【" . date('Y-m-d H:i:s') . "】批量同步商品库存到Redis写入任务【开始】执行" . PHP_EOL;
        $queue_name = RabbitmqService::SYNC_STOCK_PUBLISHER;
        $is_produce = RabbitmqService::ZERO;
        RabbitmqService::pop($queue_name, function ($consume_msg) use ($queue_name, $is_produce, $input, $output) {
            try {
                $data = json_decode($consume_msg->body, true);
                $goodsId = $data['goods_id'];
                $userId = empty($data['user_id']) ? 1 : $data['user_id'];//用户ID
                $stockKey = empty($data['redis_key']) ? (RedisService::$flushAllStockKey . '&&' . $goodsId) : $data['redis_key'];
                $stock = empty($data['stock']) ? $data['true_stock'] : $data['stock'];
                //$stockKey = $stockKey . "&&{$stock}";//redis完整热键
                if ($stock < 0) {
                    $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 2, 'key' => $stockKey, 'val' => $stock, 'total_num' => $stock, 'fail_num' => $stock, 'operate_type' => 2]);//失败
                    $msg = "商品ID【" . $goodsId . "】库存：【" . $stock . "】异常，停止更新到redis。。。。。。" . PHP_EOL;
                    RabbitmqService::insertData($is_produce, $queue_name, '同步设置商品库存到Redis异常' . PHP_EOL, $msg);
                    echo $msg;
                } else {
                    //$result = RedisService::setStock($stockKey, $stock);//直接初始化存在的可用库存数据
                    $result = Cache::set($stockKey, $stock);//直接初始化存在的可用库存数据
                    // 兼容 phpredis (bool) 和 predis (string "OK")，默认这里返回true
                    if (($result === true) || ($result === 'OK') || is_int($result)) {
                        //$redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 1, 'key' => $stockKey, 'total_num' => $stock, 'success_num' => $stock, 'val' => $stock, 'operate_type' => 2]);//直接设置成功了
                        $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'key' => $stockKey, 'val' => $stock, 'total_num' => $stock, 'operate_type' => 2]);//redis直接设置成功了，但是数据库还没处理，所以redis日志状态仍然是处理中，mq还没有真正设置
                        //执行数据库设置逻辑
                        RedisService::handleTrueSetStockData($stockKey, $goodsId, $stock, $userId, $redis_log_id, 2);
                        $msg = "商品ID【" . $goodsId . "】库存：【" . $stock . "】同步设置商品库存到Redis消费成功。。。。。。。。" . PHP_EOL;
                        RabbitmqService::insertData($is_produce, $queue_name, '同步设置商品库存到Redis消费成功' . PHP_EOL, $msg);
                        echo $msg;
                    } else {
                        $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 2, 'key' => $stockKey, 'val' => $stock, 'total_num' => $stock, 'fail_num' => $stock, 'operate_type' => 2]);//失败
                        $msg = "商品ID【" . $goodsId . "】库存：【" . $stock . "】同步设置商品库存到Redis到Redis《《失败》》" . PHP_EOL;
                        RabbitmqService::insertData($is_produce, $queue_name, '同步设置商品库存到Redis失败' . PHP_EOL, $msg);
                        echo $msg;
                    }
                }
            } catch (\Exception $e) {
                $msg = "同步设置商品库存到Redis到Redis错误，异常信息是：【" . $e->getMessage() . "】" . PHP_EOL;
                RabbitmqService::insertData($is_produce, $queue_name, '同步设置商品库存到Redis到Redis错误' . PHP_EOL, $msg);
                echo $msg;
            }
            $consume_msg->ack();//手动确认
        });


        echo "\n【" . date('Y-m-d H:i:s') . "】批量同步设置商品库存到Redis写入任务【结束】执行" . PHP_EOL;
        // 记录结束时间
        $time_end = microtime(true);
        // 计算并打印执行时间
        $execution_time = ($time_end - $time_start);
        echo "\n【" . date('Y-m-d H:i:s') . "】脚本执行时间总共：【" . $execution_time . "】 秒。";
    }

}
