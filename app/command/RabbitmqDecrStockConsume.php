<?php

namespace app\command;

use app\service\RabbitmqService;
use app\service\RedisService;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Cache;

class RabbitmqDecrStockConsume extends Command
{
    protected function configure()
    {
        $this->setName('mq:decr_stock_consume')->setDescription('商品扣减库存到Redis消费者');
    }


    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \AMQPConnectionException
     * @throws \ErrorException
     * @author foxme
     * @date 2026/6/18 14:52
     * Description: 库存扣减处理消费逻辑
     */
    protected function execute(Input $input, Output $output)
    {
        set_time_limit(0); // 不限制脚本执行时间
        // 记录开始时间
        $time_start = microtime(true);
        echo "\n【" . date('Y-m-d H:i:s') . "】批量同步扣减商品库存到Redis写入任务【开始】执行" . PHP_EOL;
        $queue_name = RabbitmqService::SYNC_DECR_STOCK_PUBLISHER;
        $is_produce = RabbitmqService::ZERO;
        RabbitmqService::pop($queue_name, function ($consume_msg) use ($queue_name, $is_produce, $input, $output) {
            try {
                $data = json_decode($consume_msg->body, true);
                $goodsId = empty($data['goods_id']) ? 1 : $data['goods_id'];
                $userId = empty($data['user_id']) ? 1 : $data['user_id'];//固定为1，表示超管
                $stockKey = empty($data['redis_key']) ? (RedisService::$flushAllStockKey . RedisService::$connectStr . $goodsId) : $data['redis_key'];
                $stock = empty($data['stock']) ? $data['true_stock'] : $data['stock'];//实际为true_stock,等于stock是为了兼容旧数据
                //$stockKey = $stockKey . "&&{$stock}";//redis完整热键
                if ($stock < 0) {
                    $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 2, 'key' => $stockKey, 'val' => $stock, 'total_num' => $stock, 'fail_num' => $stock, 'operate_type' => 0]);//直接扣减异常结束了
                    $msg = "商品ID【" . $goodsId . "】库存：【" . $stock . "】异常，停止更新到redis。。。。。。" . PHP_EOL;
                    RabbitmqService::insertData($is_produce, $queue_name, '同步扣减商品库存异常' . PHP_EOL, $msg);
                    echo $msg;
                } else {
                    //$result = Cache::dec($stockKey, $stock);//这里用lua脚本去操作扣减库存值，不能直接操作扣减
                    $result = RedisService::handleStockData($stockKey, $goodsId, $stock, 0, $userId);//操作类型给0表示减掉库存
                    // 兼容 phpredis (bool) 和 predis (string "OK")，默认这里返回true
                    if (($result === true) || ($result === 'OK') || (is_int($result))) {
                        //redis同步扣减成功之后，立马将库存数据写入redis_log日志表并返回redis_log_id
                        $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'key' => $stockKey, 'val' => $stock, 'total_num' => $stock, 'operate_type' => 0]);//redis直接扣减成功了，但是数据库还没处理，所以redis日志状态仍然是处理中，mq还没有真正扣减
                        //$output->writeln("商品库存同步到Redis成功: goods_id={$goodsId}, stock={$stock}");
                        //写入rabbit_mq队列，真正的去扣减库存表里面的库存
                        //for ($i = 1; $i <= $stock; $i++) {
                        //RabbitmqService::handleInterfaceData(RabbitmqService::SYNC_LAST_DECR_STOCK_CONSUME, json_encode(['user_id' => $userId, 'i' => $i, 'goods_id' => $goodsId, 'key' => $stockKey, 'total_num' => $stock, 'val' => 1, 'redis_log_id' => $redis_log_id], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $goodsId . "，【redis日志ID】" . $redis_log_id . "redis库存扣减，同步数据库扣减【发布成功】，队列处理中......." . PHP_EOL, RabbitmqService::ONE);//一个个库存扣减循环处理
                        RabbitmqService::handleInterfaceData(RabbitmqService::SYNC_LAST_DECR_STOCK_CONSUME, json_encode(['user_id' => $userId, 'i' => 1, 'goods_id' => $goodsId, 'key' => $stockKey, 'total_num' => $stock, 'val' => $stock, 'redis_log_id' => $redis_log_id], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $goodsId . "，【redis日志ID】" . $redis_log_id . "redis库存扣减，同步数据库扣减【发布成功】，队列处理中......." . PHP_EOL, RabbitmqService::ONE);//暂时变成一次性扣减逻辑
                        // }
                        $msg = "商品ID【" . $goodsId . "】库存：【" . $stock . "】同步扣减商品库存到Redis成功，同步到数据库处理中。。。。。。。。" . PHP_EOL;
                        RabbitmqService::insertData($is_produce, $queue_name, '同步扣减商品库存到Redis成功，同步到数据库处理中' . PHP_EOL, $msg);
                        echo $msg;
                    } else {
                        $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 2, 'key' => $stockKey, 'val' => $stock, 'total_num' => $stock, 'fail_num' => $stock, 'operate_type' => 0]);//直接扣减成功了
                        //$output->writeln("商品库存同步到Redis失败: goods_id={$goodsId}, stock={$stock}");
                        $msg = "商品ID【" . $goodsId . "】库存：【" . $stock . "】同步扣减商品库存到Redis《《失败》》原因是：【" . $result . "】" . PHP_EOL;
                        RabbitmqService::insertData($is_produce, $queue_name, '同步扣减商品库存到Redis失败' . PHP_EOL, $msg);
                        echo $msg;
                    }
                }
            } catch (\Exception $e) {
                $msg = "同步扣减商品库存到Redis错误，异常信息是：【" . $e->getMessage() . "】" . PHP_EOL;
                RabbitmqService::insertData($is_produce, $queue_name, '同步扣减商品库存到Redis错误' . PHP_EOL, $msg);
                echo $msg;
            }
            $consume_msg->ack();//手动确认
        });


        echo "\n【" . date('Y-m-d H:i:s') . "】批量同步扣减商品库存到Redis写入任务【结束】执行" . PHP_EOL;
        // 记录结束时间
        $time_end = microtime(true);
        // 计算并打印执行时间
        $execution_time = ($time_end - $time_start);
        echo "\n【" . date('Y-m-d H:i:s') . "】脚本执行时间总共：【" . $execution_time . "】 秒。";
    }

}
