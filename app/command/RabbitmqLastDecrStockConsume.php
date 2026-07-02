<?php

namespace app\command;

use app\service\RabbitmqService;
use app\service\RedisService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class RabbitmqLastDecrStockConsume extends Command
{
    protected function configure()
    {
        $this->setName('mq:last_decr_stock_consume')->setDescription('商品扣减库存到数据库消费者');
    }


    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \ErrorException
     * @author foxme
     * @date 2026/6/23 11:22
     * Description: 数据库库存扣减处理消费逻辑
     */
    protected function execute(Input $input, Output $output)
    {
        set_time_limit(0); // 不限制脚本执行时间
        // 记录开始时间
        $time_start = microtime(true);
        echo "\n【" . date('Y-m-d H:i:s') . "】批量同步扣减商品库存到数据库写入任务【开始】执行" . PHP_EOL;
        $queue_name = RabbitmqService::SYNC_LAST_DECR_STOCK_CONSUME;
        $is_produce = RabbitmqService::ZERO;
        RabbitmqService::pop($queue_name, function ($consume_msg) use ($queue_name, $is_produce, $input, $output) {
            try {
                $data = json_decode($consume_msg->body, true);
                $goodsId = empty($data['goods_id']) ? 1 : $data['goods_id'];
                $i = empty($data['i']) ? 1 : $data['i'];//循环次数
                $userId = empty($data['user_id']) ? 1 : $data['user_id'];//固定为1，表示超管
                $redis_log_id = empty($data['redis_log_id']) ? 0 : $data['redis_log_id'];//redis日志ID
                $stock = empty($data['val']) ? 0 : $data['val'];//默认暂时都是1，一条条消费
                $total_num = empty($data['total_num']) ? 0 : $data['total_num'];//总库存
                $stockKey = empty($data['key']) ? (RedisService::$flushAllStockKey . RedisService::$connectStr . $goodsId) : $data['key'];
                //$stockKey = $redis_key . "&&{$stock}";//redis完整热键
                if ($i <= $total_num) {
                    $result = RedisService::handleTrueDecrStockData($stockKey, $goodsId, $stock, $total_num, $userId, $redis_log_id, $i);//数据库减掉库存
                    // 兼容 phpredis (bool) 和 predis (string "OK")，默认这里返回true
                    if (($result === true) || ($result === 'OK')) {
                        $msg = "redis日志ID【" . $redis_log_id . "】第【" . $i . "】次执行商品ID：【" . $goodsId . "】库存：【" . $stock . "】数据库同步消费成功。。。。。。。。" . PHP_EOL;
                        RabbitmqService::insertData($is_produce, $queue_name, '数据库同步扣减商品库存消费成功' . PHP_EOL, $msg);
                        echo $msg;
                    } else {
                        $msg = "redis日志ID【" . $redis_log_id . "】第【" . $i . "】次执行商品ID：【" . $goodsId . "】库存：【" . $stock . "】同步扣减商品库存到数据库《《失败》》原因是：【" . $result . "】" . PHP_EOL;
                        RabbitmqService::insertData($is_produce, $queue_name, '数据库同步扣减商品库存失败' . PHP_EOL, $msg);
                        echo $msg;
                    }
                } else {
                    $msg = "redis日志ID【" . $redis_log_id . "】第【" . $i . "】次执行商品ID：【" . $goodsId . "】库存：【" . $stock . "】同步扣减商品库存到数据库《《失败》》原因是：【已超出执行次数范围】，已跳过。。。。。。" . PHP_EOL;
                    RabbitmqService::insertData($is_produce, $queue_name, '数据库同步扣减商品库存失败' . PHP_EOL, $msg);
                    echo $msg;
                }
            } catch (\Exception $e) {
                $msg = "同步扣减商品库存到数据库错误，文件是：【" . $e->getFile() . "】，行数是：【" . $e->getLine() . "】异常信息是：【" . $e->getMessage() . "】" . PHP_EOL;
                RabbitmqService::insertData($is_produce, $queue_name, '同步扣减商品库存到数据库错误' . PHP_EOL, $msg);
                echo $msg;
            }
            $consume_msg->ack();//手动确认
        });


        echo "\n【" . date('Y-m-d H:i:s') . "】批量同步扣减商品库存到数据库写入任务【结束】执行" . PHP_EOL;
        // 记录结束时间
        $time_end = microtime(true);
        // 计算并打印执行时间
        $execution_time = ($time_end - $time_start);
        echo "\n【" . date('Y-m-d H:i:s') . "】脚本执行时间总共：【" . $execution_time . "】 秒。";
    }

}
