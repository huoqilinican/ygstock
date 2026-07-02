<?php

namespace app\command;

use app\service\RabbitmqService;
use app\service\RedisService;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Cache;
use Swoole\Coroutine\Redis;

class RabbitmqIncrStockConsume extends Command
{
    protected function configure()
    {
        $this->setName('mq:incr_stock_consume')->setDescription('商品新增库存到Redis消费者');
    }


    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \AMQPConnectionException
     * @throws \ErrorException
     * @author foxme
     * @date 2026/6/18 14:52
     * Description: 库存新增处理消费逻辑
     */
    protected function execute(Input $input, Output $output)
    {
        set_time_limit(0); // 不限制脚本执行时间
        // 记录开始时间
        $time_start = microtime(true);
        echo "\n【" . date('Y-m-d H:i:s') . "】批量同步新增商品库存到Redis写入任务【开始】执行" . PHP_EOL;
        $queue_name = RabbitmqService::SYNC_INCR_STOCK_PUBLISHER;
        $is_produce = RabbitmqService::ZERO;
        RabbitmqService::pop($queue_name, function ($consume_msg) use ($queue_name, $is_produce, $input, $output) {
            try {
                $data = json_decode($consume_msg->body, true);
                $goodsId = $data['goods_id'];
                $userId = empty($data['user_id']) ? 1 : $data['user_id'];//固定为1，表示超管
                $stockKey = empty($data['redis_key']) ? (RedisService::$flushAllStockKey . RedisService::$connectStr . $goodsId) : $data['redis_key'];
                $stock = empty($data['stock']) ? $data['true_stock'] : $data['stock'];//实际为true_stock,等于stock是为了兼容旧数据
                //$stockKey = $stockKey . "&&{$stock}";//redis完整热键
                if ($stock < 0) {
                    $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 2, 'key' => $stockKey, 'val' => $stock, 'total_num' => $stock, 'fail_num' => $stock, 'operate_type' => 1]);//失败
                    $msg = "商品ID【" . $goodsId . "】库存：【" . $stock . "】异常，停止更新到redis。。。。。。" . PHP_EOL;
                    RabbitmqService::insertData($is_produce, $queue_name, '同步新增商品库存异常' . PHP_EOL, $msg);
                    echo $msg;
                } else {
                    // $result = Cache::inc($stockKey, $stock);//新增库存值
                    $result = RedisService::handleStockData($stockKey, $goodsId, $stock, 1, $userId);//操作类型给1表示新增库存
                    // 兼容 phpredis (bool) 和 predis (string "OK")，默认这里返回true
                    if (is_int($result) && ($result != false)) {
                        $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 0, 'key' => $stockKey, 'total_num' => $stock, 'success_num' => 0, 'val' => $stock, 'operate_type' => 1]);//还没有直接成功，只是redis成功
                        //执行数据库新增逻辑
                        RedisService::handleTrueIncStockData($stockKey, $goodsId, $stock, $userId, $redis_log_id);
                        $msg = "商品ID【" . $goodsId . "】库存：【" . $stock . "】同步新增商品库存消费成功。。。。。。。。" . PHP_EOL;
                        RabbitmqService::insertData($is_produce, $queue_name, '同步新增商品库存消费成功' . PHP_EOL, $msg);
                        echo $msg;
                    } else {
                        if (empty($result) || !is_int($result)) {
                            $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => $consume_msg->body, 'status' => 2, 'key' => $stockKey, 'val' => $stock, 'total_num' => $stock, 'fail_num' => $stock, 'operate_type' => 1]);//失败
                            $msg = "商品ID【" . $goodsId . "】库存：【" . $stock . "】同步新增商品库存到Redis《《失败》》" . (!is_int($result) ? '原因是：【' . $result . '】' : '') . PHP_EOL;
                            RabbitmqService::insertData($is_produce, $queue_name, '同步新增商品库存失败' . PHP_EOL, $msg);
                            echo $msg;
                        }
                    }
                }
            } catch (\Exception $e) {
                $msg = "同步新增商品库存到Redis错误，异常信息是：【" . $e->getMessage() . "】" . PHP_EOL;
                RabbitmqService::insertData($is_produce, $queue_name, '同步新增商品库存到Redis错误' . PHP_EOL, $msg);
                echo $msg;
            }
            $consume_msg->ack();//手动确认
        });
        echo "\n【" . date('Y-m-d H:i:s') . "】批量同步新增商品库存到Redis写入任务【结束】执行" . PHP_EOL;
        // 记录结束时间
        $time_end = microtime(true);
        // 计算并打印执行时间
        $execution_time = ($time_end - $time_start);
        echo "\n【" . date('Y-m-d H:i:s') . "】脚本执行时间总共：【" . $execution_time . "】 秒。";
    }

}
