<?php

namespace app\service;

use app\model\RedisLog;
use app\model\RedisResultLog;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Validate;

class RedisService
{
    private static $redis;
    private static $luaScriptSha;  // Lua脚本的SHA值（用于EVALSHA优化）
    private static $initialized = false; //
    public static $flushAllStockKey = 'seayayun:stock';//正式使用
    //public static $flushAllStockKey = 'flush:stock';//临时本地测试使用
    public static $connectStr = '&&';
    public static $flushIncrAllStockKey = 'seayayun:incr_stock';
    public static $flushDecrAllStockKey = 'seayayun:decr_stock';

    private static $stockLuaSha = null;
    private static $stockLuaScript = <<<LUA
-- 原子扣减库存脚本（无需额外锁）
local key = KEYS[1]
local qty = tonumber(ARGV[1])

-- 获取当前库存
local stock = redis.call('GET', key)
if stock == false then
    stock = 0
else
    stock = tonumber(stock)
end

-- 库存不足
if stock < qty then
    return -1
end

-- 执行扣减
local newStock = redis.call('DECRBY', key, qty)
return newStock
LUA;

    /**
     * 初始化 Redis 连接（延迟加载）
     */
    private static function init()
    {
        if (!self::$initialized) {
            self::$redis = Cache::store('redis')->handler();
            // 加载Lua脚本并缓存SHA（减少网络传输）
            $luaScript = self::getLuaScript();
            self::$luaScriptSha = self::$redis->script('load', $luaScript);
            self::$initialized = true;
        }
    }


    /**
     * 获取脚本 SHA1（缓存避免重复加载）
     */
    private static function getScriptSha($redis)
    {
        if (self::$stockLuaSha === null) {
            self::$stockLuaSha = $redis->script('load', self::$stockLuaScript);
        }
        return self::$stockLuaSha;
    }

    /**
     * @return mixed
     * @author foxme
     * @date 2026/6/26 15:42
     * Description: 获取 Redis 实例
     */
    private static function getRedis()
    {
        self::init();  // 确保已初始化
        return self::$redis;
    }

    /**
     * @return string
     * @author foxme
     * @date 2026/6/22 14:26
     * Description: 纯lua脚本执行扣减库存逻辑
     */
    private static function getLuaScript()
    {
        return <<<LUA
            local stockKey = KEYS[1]
            local buyNum = ARGV[1]
            
           
            -- 库存检查
            local stock = redis.call('GET', stockKey)
            if not stock or stock <= 0 then
                return -1
            end
            
            if stock >= buyNum then
                local newStock = redis.call('DECRBY', stockKey, buyNum)
                return newStock
            end
            
            return -1
LUA;
    }


    /**
     * @param $data
     * @return array
     * @author foxme
     * @date 2026/6/22 14:12
     * Description: 写入redis日志
     */
    public static function logData($data)
    {
        return RedisLog::handleData($data);
    }


    /**
     * @param $data
     * @return array
     * @author foxme
     * @date 2026/6/23 17:07
     * Description: 写入redis日志结果日志表
     */
    public static function logRedisResult($data)
    {
        return RedisResultLog::handleData($data);
    }

    /**
     * @param $params
     * @return array|string
     * @author foxme
     * @date 2026/6/22 14:34
     * Description: 验证所传商品ID和库存值是否合规
     */
    public static function commonValidate($params)
    {
        $validate = Validate::rule([
            'goods_id' => 'require|integer',
            'num' => 'require|integer|between:1,5'  // 暂时固定每人限购5件
        ]);

        if (!$validate->check($params)) {
            // return json(['code' => 400, 'msg' => $validate->getError()]);
            return $validate->getError();
        }
        return true;
    }


    /**
     * @param $userId
     * @return bool
     * @author foxme
     * @date 2026/6/22 14:45
     * Description: 令牌桶限流
     */
    private static function checkRateLimit($userId)
    {
        $key = "rate_limit:{$userId}";
        $rate = 10;   // 每秒10个令牌
        $capacity = 50;  // 桶容量50

        $now = microtime(true);
        $tokens = self::getStock($key);

        if ($tokens === false) {
            // 初始化桶
            self::setStock($key, $capacity - 1);
            self::setExpire($key, 1);
            return false;
        }

        $lastTime = self::getStock("{$key}:time") ?: $now;
        $delta = $now - $lastTime;
        $newTokens = min($capacity, $tokens + $delta * $rate);

        if ($newTokens < 1) {
            return true;  // 被限流
        }

        // 消耗一个令牌
        self::setStock($key, $newTokens - 1);
        self::setStock("{$key}:time", $now);
        return false;
    }


    /**
     * @param $key
     * @return mixed
     * @author foxme
     * @date 2026/6/26 15:42
     * Description: 获取库存
     */
    public static function getStock($key)
    {
        return self::getRedis()->get($key);
    }


    /**
     * @param $key
     * @return mixed
     * @author foxme
     * @date 2026/6/26 18:51
     * Description: 删除指定redis缓存键值
     */
    public static function delStock($key)
    {
        return self::getRedis()->del($key);
    }


    public static function updateStock($stockKey, $goodsId)
    {
        $warehouse_id = Db::name('goods')->where('goods_id', $goodsId)->value('stock_warehouse_id');
        if (!empty($warehouse_id)) Db::name('stock_warehouse')->where('id', $warehouse_id)->update(['goods_num' => 0]);//清空可用库存
    }

    /**
     * @param $stockKey
     * @param $stockNum
     * @return mixed
     * @author foxme
     * @date 2026/6/29 17:39
     * Description: 处理lua脚本扣减库存逻辑
     */
    public static function luaScript($stockKey, $stockNum)
    {
        // 优先使用EVALSHA扣减redis库存（性能更好）
        $result = self::getRedis()->evalSha(self::$luaScriptSha, [
            $stockKey, $stockNum,
        ], 1);
        // 兜底策略，如果EVALSHA失败，降级到EVAL
        if ($result === false) {
            $result = self::getRedis()->eval(self::getLuaScript(), [
                $stockKey, $stockNum
            ], 1);
        }
        return $result;
    }


    /**
     * Redis 原子扣减库存（Lua脚本）
     * @param Redis $redis
     * @param string $key 库存的 Redis key
     * @param int $qty 扣减数量
     * @return bool            true:扣减成功, false:库存不足
     * @author foxme
     * @date 2026/6/22 14:35
     * Description: （支持多 KEY 多 ARGV） （单个扣减）
     */
    public static function decrStock($key, $qty)
    {

        $redis = self::getRedis();//初始化redis

        // 1. 尝试使用 EVALSHA（性能最优）
        $sha = self::getScriptSha($redis);
        $result = $redis->evalSha($sha, [$key, $qty], 1);

        // 2. 如果 EVALSHA 失败（脚本未加载），降级到 EVAL
        if ($result === false || $result === null) {
            $result = $redis->eval(self::$stockLuaScript, [$key, $qty], 1);
        }

        return $result;

    }


    /**
     * @param $goodsKeys
     * @param $quantities
     * @return bool
     * @author foxme
     * @date 2026/6/22 15:13
     * Description:  // 多库存扣减，参数：KEYS 为多个商品key，ARGV 为对应数量，都是数组
     * 如：$goodsKeys = ["goods:1:stock", "goods:2:stock"]； $quantities = [1, 2];
     */
    public static function decrMutilStock($goodsKeys, $quantities)
    {
        $luaMulti = <<<LUA
local keys = KEYS          -- 多个商品key
local qtys = ARGV          -- 对应的扣减数量（字符串数组）
local i
for i = 1, #keys do
    local key = keys[i]
    local qty = tonumber(qtys[i])
    local stock = redis.call('get', key)
    if not stock or tonumber(stock) < qty then
        return 0   -- 任意一个不足则全部失败
    end
end
-- 全部足够，执行扣减
for i = 1, #keys do
    redis.call('decrby', keys[i], qtys[i])
end
return 1
LUA;
        // 合并参数：先放 KEYS，再放 ARGV
        $args = array_merge($goodsKeys, $quantities);
        $result = self::getRedis()->eval($luaMulti, $args, count($goodsKeys));
        return $result == 1;
    }


    /**
     * @param $stockKey
     * @param $goodsId
     * @param $stock
     * @param int $operation
     * @param int $userId
     * @return bool|false|int|string
     * @author foxme
     * @date 2026/6/22 15:08
     * Description: 公共处理库存（新增或减少）
     */
    public static function handleStockData($stockKey, $goodsId, $stock, $operation = 0, $userId = 1)
    {

//        $validate = self::commonValidate(['goods_id'=>$goodsId,'num'=>$stock]);
//        if($validate != true) return $validate;


        if (empty($operation) || ($operation == 0)) {
//            $rateLimit = self::checkRateLimit($userId);
//            if ($rateLimit != true) return '您的操作太频繁,请稍后再试！';
            //执行Lua脚本（原子扣减）
            $success = self::decrStock($stockKey, $stock);
            return ($success === -1) ? "库存不足" : $success;//等于0就表示库存不足
        } else {
            if ($operation == 1) {
                //执行Lua脚本（原子新增）
                $success = self::incStock($stockKey, $stock);//返回int或false
                return $success;
            }
        }
    }


    /**
     * @param $stockKey
     * @param $stock
     * @return mixed
     * @author foxme
     * @date 2026/6/26 15:51
     * Description: 自增指定键的库存值
     */
    public static function incStock($stockKey, $stock)
    {
        return self::getRedis()->incrby($stockKey, $stock);
    }


    /**
     * @param $stockKey
     * @param $stock
     * @return mixed
     * @author foxme
     * @date 2026/6/26 15:51
     * Description: 初始化设置指定redis键的库存值
     */
    public static function setStock($stockKey, $stock)
    {
        return self::getRedis()->set($stockKey, $stock);
    }

    /**
     * @param $key
     * @param $time
     * @return mixed
     * @author foxme
     * @date 2026/6/26 15:59
     * Description: 设置某个redis键的过期时间（单位秒）
     */
    public static function setExpire($key, $time)
    {
        return self::getRedis()->expire($key, $time);
    }

    /**
     * @param $stockKey
     * @param $goodsId
     * @param $stock
     * @param int $userId
     * @param $redis_log_id
     * @param $i
     * @return bool|string
     * @author foxme
     * @date 2026/6/23 18:18
     * Description: 最终执行扣减库存处理逻辑（主要是数据库处理）
     */
    public static function handleTrueDecrStockData($stockKey, $goodsId, $stock, $total_num, $userId = 1, $redis_log_id, $i)
    {
        $buyNum = $stock;
        $msg = '';
//        $redis_key = $stockKey . $redis_log_id;
//        $token = uniqid(mt_rand(), true);
//        $ttl = 86400;
//        $redis_lock = self::getRedis()->set($redis_key, $token, ['nx', 'ex' => $ttl]);//利用redis分布式锁的特性
//        if (!$redis_lock) return '另一进程正在处理，本任务跳过！';
        $data = ['goods_id' => $goodsId, 'stock' => $stock, 'total_num' => $total_num, 'redis_log_id' => $redis_log_id, 'redis_key' => $stockKey, 'user_id' => $userId];
        //开启事务
        Db::startTrans();
        try {
            $warehouse_id = Db::name('goods')->where('goods_id', $goodsId)->value('stock_warehouse_id');
            if (empty($warehouse_id)) {
                self::handleFailedJob($data, "redis日志ID【" . $redis_log_id . "】第【" . $i . "】次商品ID：【" . $goodsId . "】所属仓库ID为空");
                $msg = "商品所属仓库ID为空！";
            }
            if (empty($msg)) {
                // 1. 再次校验数据库库存（二次确认）
                $affected = Db::name('stock_warehouse')
                    ->where('id', $warehouse_id)
                    ->where('goods_num', '>=', $buyNum)
                    ->dec('goods_num', $buyNum)
                    ->update();//自减操作
                if ($affected === 0) {
                    self::handleFailedJob($data, "redis日志ID【" . $redis_log_id . "】第【" . $i . "】次商品ID：【" . $goodsId . "】数据库库存不足");
                    $msg = "仓库数据库库存不足！";
                }
            }
            if (empty($msg)) self::handleLastMessage($data);//算是成功了，写入redis日志结果表
            Db::commit();
            // 5. 发送通知（异步，不影响主流程）
            //self::sendNotification($userId, $orderId);//暂未对接
            return empty($msg) ? true : $msg;
        } catch (\Exception $e) {
            Db::rollback();//回滚事务
            // 记录到失败队列
            self::handleFailedJob($data, $e->getMessage());
            return "操作异常，原因是：【" . $e->getMessage() . "】" . PHP_EOL;
        }

    }


    /**
     * @param $stockKey
     * @param $goodsId
     * @param $stock
     * @param int $userId
     * @param $redis_log_id
     * @return bool|string
     * @author foxme
     * @date 2026/6/29 12:07
     * Description: 最终执行新增库存处理逻辑（主要是数据库处理）
     */
    public static function handleTrueIncStockData($stockKey, $goodsId, $stock, $userId, $redis_log_id)
    {
        $buyNum = $stock;
        $msg = '';
        $data = ['goods_id' => $goodsId, 'stock' => $stock, 'total_num' => $stock, 'redis_log_id' => $redis_log_id, 'redis_key' => $stockKey, 'user_id' => $userId];
        //开启事务
        Db::startTrans();
        try {
            $warehouse_id = Db::name('goods')->where('goods_id', $goodsId)->value('stock_warehouse_id');
            if (empty($warehouse_id)) {
                self::handleFailedJob($data, "redis日志ID【" . $redis_log_id . "】商品ID：【" . $goodsId . "】所属仓库ID为空");
                $msg = "商品所属仓库ID为空！";
            }
            if (empty($msg)) {
                // 1. 再次校验数据库库存（二次确认）
                $affected = Db::name('stock_warehouse')
                    ->where('id', $warehouse_id)
                    ->inc('goods_num', $buyNum)
                    ->update();//自增操作
                if ($affected === 0) {
                    self::handleFailedJob($data, "redis日志ID【" . $redis_log_id . "】商品ID：【" . $goodsId . "】数据库库存增加失败");
                    $msg = "仓库数据库增加失败！";
                }
            }
            if (empty($msg)) {
                $data['type'] = 0;//成功初始化type为0
                $data['exception'] = '';//清空异常信息
                self::handleLastMessage($data);
            }//算是成功了，写入redis日志结果表
            Db::commit();
            // 5. 发送通知（异步，不影响主流程）
            //self::sendNotification($userId, $orderId);//暂未对接
            return empty($msg) ? true : $msg;
        } catch (\Exception $e) {
            Db::rollback();//回滚事务
            // 记录到失败队列
            self::handleFailedJob($data, $e->getMessage());
            return "操作异常，原因是：【" . $e->getMessage() . "】" . PHP_EOL;
        }
    }


    /**
     * @param $stockKey
     * @param $goodsId
     * @param $stock
     * @param $userId
     * @param $redis_log_id
     * @param $operate_type
     * @return bool|string
     * @author foxme
     * @date 2026/6/29 19:06
     * Description: 设置（初始化）指定redis键值
     */
    public static function handleTrueSetStockData($stockKey, $goodsId, $stock, $userId, $redis_log_id, $operate_type)
    {
        $buyNum = $stock;
        $msg = '';
        $data = ['operate_type' => $operate_type, 'goods_id' => $goodsId, 'stock' => $stock, 'total_num' => $stock, 'redis_log_id' => $redis_log_id, 'redis_key' => $stockKey, 'user_id' => $userId];
        //开启事务
        Db::startTrans();
        try {
            $warehouse_id = Db::name('goods')->where('goods_id', $goodsId)->value('stock_warehouse_id');
            if (empty($warehouse_id)) {
                self::handleFailedJob($data, "redis日志ID【" . $redis_log_id . "】商品ID：【" . $goodsId . "】所属仓库ID为空");
                $msg = "商品所属仓库ID为空！";
            }
            if (empty($msg)) {
                // 1. 再次校验数据库库存（二次确认）
                $affected = Db::name('stock_warehouse')
                    ->where('id', $warehouse_id)
                    ->update(['goods_num' => $buyNum]);//更新操作
                if ($affected === 0) {
                    self::handleFailedJob($data, "redis日志ID【" . $redis_log_id . "】商品ID：【" . $goodsId . "】数据库库存设置失败");
                    $msg = "仓库数据库设置失败！";
                }
            }
            if (empty($msg)) {
                $data['type'] = 0;//成功初始化type为0
                $data['exception'] = '';//清空异常信息
                self::handleLastMessage($data);
            }//算是成功了，写入redis日志结果表
            Db::commit();
            // 5. 发送通知（异步，不影响主流程）
            //self::sendNotification($userId, $orderId);//暂未对接
            return empty($msg) ? true : $msg;
        } catch (\Exception $e) {
            Db::rollback();//回滚事务
            // 记录到失败队列
            self::handleFailedJob($data, $e->getMessage());
            return "操作异常，原因是：【" . $e->getMessage() . "】" . PHP_EOL;
        }
    }


    /**
     * @param $data
     * @param $errorMsg
     * @author foxme
     * @date 2026/6/23 17:29
     * Description: 发送告警信息
     */
    public static function sendAlert($data, $errorMsg)
    {
        // 发送告警到钉钉/企业微信
        $webhook = "https://oapi.dingtalk.com/robot/send";
        $message = "秒杀订单失败告警\n商品ID: {$data['goods_id']}\n用户ID: {$data['user_id']}\n错误: {$errorMsg}";
        // 发送HTTP请求...
    }

    /**
     * @param $data
     * @param $errorMsg
     * @author foxme
     * @date 2026/6/23 12:03
     * Description: 处理失败的任务
     */
    public static function handleFailedJob($data, $errorMsg)
    {
        // 回滚Redis库存
        $stockKey = $data['redis_key'];
        if (!empty($data['operate_type']) && ($data['operate_type'] == 2)) {
            self::setStock($stockKey, 0);//回退重置为0
        } else {
            self::incStock($stockKey, $data['stock']);//回退的话则是按照消费队列一条条回退，以为生产也是一条条循环遍历生产的
        }
        $data['type'] = 1;//表示失败
        $data['exception'] = $errorMsg;//只有失败才算是异常信息
        self::handleLastMessage($data, $errorMsg);//判断处理是否真正已完成此任务
    }


    /**
     * @param $data
     * @param string $msg
     * @throws \think\db\exception\DbException
     * @author foxme
     * @date 2026/6/23 17:35
     * Description: 综合判断处理成功或者失败逻辑
     */
    public static function handleLastMessage($data, $msg = '')
    {
        //写入结果表
        if (empty($msg)) {
            if (!empty($data['operate_type']) && ($data['operate_type'] == 2)) {
                //设置操作时
                Db::name('redis_log')->where('redis_id', $data['redis_log_id'])->update(['success_num' => $data['stock']]);
            } else {
                //扣减操作时，更新成功数自增
                Db::name('redis_log')->where('redis_id', $data['redis_log_id'])->inc('success_num', $data['stock'])->update();
            }
        }
        if (!empty($msg)) {
            if (!empty($data['operate_type']) && ($data['operate_type'] == 2)) {
                //设置操作时
                Db::name('redis_log')->where('redis_id', $data['redis_log_id'])->update(['fail_num' => $data['stock']]);
            } else {
                //扣减操作时，更新失败数自增
                Db::name('redis_log')->where('redis_id', $data['redis_log_id'])->inc('fail_num', $data['stock'])->update();
            }
        }
        if (!empty($data['operate_type'])) unset($data['operate_type']);//将次字段过滤掉
        self::logRedisResult($data);
        //最后每次都判断，是否是最后一笔消费记录，如果是，则更新其redis操作记录状态为已完成
        self::isLastConsumeData($data);
    }

    /**
     * @param $data
     * @author foxme
     * @date 2026/6/23 17:24
     * Description: 更新最后日志状态
     */
    public static function isLastConsumeData($data)
    {
        $redis_num_data = RedisLog::querySuccOrFailData($data['redis_log_id']);
        $fail_num = empty($redis_num_data['fail_num']) ? 0 : $redis_num_data['fail_num'];
        $succ_num = empty($redis_num_data['success_num']) ? 0 : $redis_num_data['success_num'];

        //下面这种查询方式已废弃，不太友好，用上面那种查询方式
//        $fail_num = intval(RedisResultLog::isFailData($data['redis_log_id']));//高并发的时候这里可能会大量查询
//        $succ_num = intval(RedisResultLog::isSuccessData($data['redis_log_id']));//高并发的时候这里可能会大量查询

        if (intval($data['total_num']) == intval($fail_num + $succ_num)) {
            //表示此条库存日志数据消费完毕了，则表示成功
            RedisLog::updateStatus($data['redis_log_id']);//表示处理完成，直接更新状态为已完成
            if ($succ_num == 0) self::sendAlert($data, $data['exception']);// 只有全部失败才会发送告警，暂未对接
        }
    }
}
