<?php
/**
Create by foxme
* Date: 2026 / 6 / 16 19:00
* Description: 秒杀api
**/


namespace app\controller;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use think\facade\Validate;
use app\BaseController;

class SecondKillController extends BaseController
{
    private $redis;
    private $luaScriptSha;  // Lua脚本的SHA值（用于EVALSHA优化）

    public function __construct()
    {
        $this->redis = $this->redis->store('redis')->handler();
        // 加载Lua脚本并缓存SHA（减少网络传输）
        $luaScript = $this->getLuaScript();
        $this->luaScriptSha = $this->redis->script('load', $luaScript);
    }



    /**
     * 秒杀入口
     */
    public function seckill()
    {
        // 1. 参数校验
        $params = $this->request->post();
        $validate = Validate::rule([
            'goods_id' => 'require|integer',
            'num' => 'require|integer|between:1,5'  // 每人限购5件
        ]);

        if (!$validate->check($params)) {
            return json(['code' => 400, 'msg' => $validate->getError()]);
        }

        $goodsId = $params['goods_id'];
        $buyNum = $params['num'];
        $userId = $this->getUserId();  // 从token获取用户ID

        // 2. 限流检查（令牌桶算法）
        $limited = $this->checkRateLimit($userId);
        if ($limited) {
            return json(['code' => 429, 'msg' => '请求过于频繁，请稍后再试']);
        }

        // 3. 执行Lua脚本（原子扣减）
        $stockKey = "seckill:stock:{$goodsId}";
        $userKey = "seckill:user:{$goodsId}";  // 记录已秒杀到的用户
        $ttl = 3600;  // 记录保留1小时

        try {
            // 方式1：使用EVALSHA（性能更好）
            $result = $this->redis->evalSha($this->luaScriptSha, [
                $stockKey, $userKey, $buyNum, $userId, $ttl
            ], 2);  // 2表示有2个KEY参数

            // 方式2：如果EVALSHA失败，降级到EVAL
            if ($result === false) {
                $luaScript = $this->getLuaScript();
                $result = $this->redis->eval($luaScript, [
                    $stockKey, $userKey, $buyNum, $userId, $ttl
                ], 2);
            }

        } catch (\Exception $e) {
            Log::error("Lua执行失败", ['error' => $e->getMessage()]);
            return json(['code' => 500, 'msg' => '系统错误']);
        }

        // 4. 处理Lua返回结果
        if ($result === -1) {
            return json(['code' => 1, 'msg' => '已抢光']);
        }

        if ($result === -2) {
            return json(['code' => 1, 'msg' => '您已经抢到过啦']);
        }

        if ($result < 0) {
            return json(['code' => 1, 'msg' => '秒杀失败']);
        }

        // 5. Redis扣减成功，推送消息到队列
        $queueData = [
            'goods_id' => $goodsId,
            'user_id' => $userId,
            'num' => $buyNum,
            'seckill_time' => time(),
            'retry_count' => 0
        ];

        // 推送到队列（支持延迟队列和优先级）
        Queue::push('app\job\SeckillOrderJob', $queueData, 'seckill');

        // 6. 立即返回成功（排队中）
        return json([
            'code' => 0,
            'msg' => '抢购成功，正在生成订单',
            'data' => [
                'queue_status' => 'pending',
                'estimated_time' => 5  // 预计5秒内完成
            ]
        ]);
    }

    /**
     * 查询秒杀结果
     */
    public function queryResult()
    {
        $goodsId = $this->request->get('goods_id');
        $userId = $this->getUserId();

        // 先查Redis缓存
        $cacheKey = "seckill:result:{$goodsId}:{$userId}";
        $result = $this->redis->get($cacheKey);

        if ($result) {
            return json(json_decode($result, true));
        }

        // 未命中缓存，查数据库
        $order = Db::name('seckill_orders')
            ->where('goods_id', $goodsId)
            ->where('user_id', $userId)
            ->find();

        if ($order) {
            $data = [
                'code' => 0,
                'msg' => '抢购成功',
                'data' => ['order_id' => $order['id']]
            ];
            $this->redis->set($cacheKey, json_encode($data), 300);  // 缓存5分钟
            return json($data);
        }

        return json(['code' => 1, 'msg' => '抢购失败或尚未处理完成']);
    }

    /**
     * 令牌桶限流
     */
    private function checkRateLimit($userId)
    {
        $key = "rate_limit:{$userId}";
        $rate = 10;   // 每秒10个令牌
        $capacity = 50;  // 桶容量50

        $now = microtime(true);
        $tokens = $this->redis->get($key);

        if ($tokens === false) {
            // 初始化桶
            $this->redis->set($key, $capacity - 1);
            $this->redis->expire($key, 1);
            return false;
        }

        $lastTime = $this->redis->get("{$key}:time") ?: $now;
        $delta = $now - $lastTime;
        $newTokens = min($capacity, $tokens + $delta * $rate);

        if ($newTokens < 1) {
            return true;  // 被限流
        }

        // 消耗一个令牌
        $this->redis->set($key, $newTokens - 1);
        $this->redis->set("{$key}:time", $now);
        return false;
    }

    private function getLuaScript()
    {
        return <<<LUA
            local stockKey = KEYS[1]
            local userKey = KEYS[2]
            local buyNum = ARGV[1]
            local userId = ARGV[2]
            local expireTime = ARGV[3]
            
            -- 防重复检查
            local isUserExists = redis.call('SISMEMBER', userKey, userId)
            if isUserExists == 1 then
                return -2
            end
            
            -- 库存检查
            local stock = redis.call('GET', stockKey)
            if not stock or stock <= 0 then
                return -1
            end
            
            if stock >= buyNum then
                local newStock = redis.call('DECRBY', stockKey, buyNum)
                redis.call('SADD', userKey, userId)
                redis.call('EXPIRE', userKey, expireTime)
                return newStock
            end
            
            return -1
LUA;
    }

    private function getUserId()
    {
        // 实际从JWT token中获取
        return $this->request->middleware('auth')->userId ?? 1;
    }
}