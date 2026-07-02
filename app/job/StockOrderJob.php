<?php

namespace app\job;

use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;
use think\queue\Job;

class StockOrderJob
{
    /**
     * 处理秒杀订单（数据库扣减逻辑）
     */
    public function fire(Job $job, $data)
    {
        $goodsId = $data['goods_id'];
        $userId = $data['user_id'];
        $buyNum = $data['num'];
        $retryCount = $data['retry_count'] ?? 0;

        Log::info("处理秒杀订单", ['data' => $data]);

        // 幂等性检查（防止重复消费）
        $idempotentKey = "idempotent:seckill:{$goodsId}:{$userId}";
        $processed = Cache::get($idempotentKey);

        if ($processed) {
            Log::warning("重复消费", ['data' => $data]);
            $job->delete();
            return;
        }

        Db::startTrans();

        try {
            // 1. 再次校验数据库库存（二次确认）
            $affected = Db::name('goods')
                ->where('id', $goodsId)
                ->where('stock', '>=', $buyNum)
                ->update([
                    'stock' => Db::raw('stock - ' . $buyNum),
                    'sales' => Db::raw('sales + ' . $buyNum)
                ]);

            if ($affected === 0) {
                throw new \Exception('数据库库存不足');
            }

            // 2. 创建订单
            $orderId = Db::name('seckill_orders')->insertGetId([
                'order_sn' => $this->generateOrderSn(),
                'goods_id' => $goodsId,
                'user_id' => $userId,
                'num' => $buyNum,
                'status' => 0,  // 待支付
                'seckill_time' => $data['seckill_time'],
                'create_time' => time()
            ]);

            // 3. 标记幂等
            Cache::set($idempotentKey, $orderId, 86400);  // 24小时有效期

            // 4. 更新Redis中的用户订单信息
            $userOrderKey = "seckill:order:{$goodsId}:{$userId}";
            Cache::set($userOrderKey, $orderId, 3600);

            Db::commit();

            // 5. 发送通知（异步，不影响主流程）
            $this->sendNotification($userId, $orderId);

            // 6. 删除队列任务
            $job->delete();

            Log::info("秒杀订单创建成功", ['order_id' => $orderId]);

        } catch (\Exception $e) {
            Db::rollback();

            Log::error("秒杀订单创建失败", [
                'error' => $e->getMessage(),
                'data' => $data,
                'retry_count' => $retryCount
            ]);

            // 重试逻辑（最多3次）
            if ($retryCount < 3) {
                $data['retry_count'] = $retryCount + 1;
                $job->release(5);  // 5秒后重试
            } else {
                // 超过重试次数，记录到失败队列
                $this->handleFailedJob($data, $e->getMessage());
                $job->delete();
            }
        }
    }

    /**
     * 处理失败的任务
     */
    private function handleFailedJob($data, $errorMsg)
    {
        Db::name('failed_jobs')->insert([
            'queue' => 'seckill',
            'payload' => json_encode($data),
            'exception' => $errorMsg,
            'failed_at' => time()
        ]);

        // 回滚Redis库存
        $redis = Cache::store('redis')->getRedis();
        $stockKey = "seckill:stock:{$data['goods_id']}";
        $userKey = "seckill:user:{$data['goods_id']}";
        $redis->incrby($stockKey, $data['num']);
        $redis->srem($userKey, $data['user_id']);

        // 发送告警
        $this->sendAlert($data, $errorMsg);
    }

    private function generateOrderSn()
    {
        return 'SK' . date('YmdHis') . mt_rand(10000, 99999);
    }

    private function sendNotification($userId, $orderId)
    {
        // 推送到消息队列，由另一个消费者发送短信/推送
        Queue::push('app\job\SendNotificationJob', [
            'user_id' => $userId,
            'order_id' => $orderId,
            'type' => 'seckill_success'
        ]);
    }

    private function sendAlert($data, $errorMsg)
    {
        // 发送告警到钉钉/企业微信
        $webhook = "https://oapi.dingtalk.com/robot/send";
        $message = "秒杀订单失败告警\n商品ID: {$data['goods_id']}\n用户ID: {$data['user_id']}\n错误: {$errorMsg}";
        // 发送HTTP请求...
    }
}