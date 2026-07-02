<?php
/**
 * Create by foxme
 * Date: 2026 / 6 / 26 11:23
 * Description: 操作库存api控制器入口
 **/


namespace app\controller;

use app\service\RabbitmqService;
use app\service\RedisService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\Validate;
use app\BaseController;

class OperateStockController extends BaseController
{

    /**
     * @return \think\response\Json
     * @author foxme
     * @date 2026/6/26 12:13
     * Description: 扣减库存
     */
    public function decrStock()
    {
        // 1. 参数校验
        $params = input('post.');

        $queue_name = RabbitmqService::SYNC_LAST_DECR_STOCK_CONSUME;

        $validate = Validate::rule([
            'user_id' => 'number|unique:users',
            'goods_id' => 'require|number',
            'num' => 'require|number|min:1',
            'bf_num' => 'number|min:1',
        ]);

        if (!$validate->check($params)) {
            return json(['code' => 400, 'msg' => $validate->getError()]);
        }

        $userId = empty($params['user_id']) ? 1 : intval($params['user_id']);//如果为空，则暂时默认固定为超管用户ID为1
        $goodsId = intval($params['goods_id']);//商品ID
        $stockNum = intval($params['num']);//需要操作的库存数量
        $bfNum = empty($params['bf_num']) ? 0 : intval($params['bf_num']);//并发的数量

//        // 2. 限流检查（令牌桶算法）
//        $limited = $this->checkRateLimit($userId);
//        if ($limited) {
//            return json(['code' => 429, 'msg' => '请求过于频繁，请稍后再试']);
//        }

        // 3. 执行Lua脚本（原子扣减）
        $stockKey = RedisService::$flushAllStockKey . RedisService::$connectStr . $goodsId;

        //print_r(RedisService::getStock($stockKey));echo "<br>";

//        $getStockNum = RedisService::getStock($stockKey) ?? 0;//扣减之前，可以先查一遍库存是否存在
//
//        if (empty($getStockNum)) {
//            return json(['code' => 400, 'msg' => "goods_id【" . intval($goodsId) . "】库存为0，请及时处理！"]);
//        }
//
//        if ($getStockNum < $stockNum) {
//            return json(['code' => 400, 'msg' => "goods_id【" . intval($goodsId) . "】库存不足【" . $getStockNum . "】，请及时处理！"]);
//        }

        try {
            $result = RedisService::decrStock($stockKey, $stockNum);//print_r($result);
        } catch (\Exception $e) {
            Log::error("Lua执行失败", ['error' => $e->getMessage()]);
            return json(['code' => 500, 'msg' => "goods_id【" . intval($goodsId) . "】系统错误"]);
        }
        // 4. 处理Lua返回结果
        if ($result === -1) {
            return json(['code' => 400, 'msg' => "goods_id【" . intval($goodsId) . "】库存不够"]);
        }

        if ($result < 0) {
            return json(['code' => 400, 'msg' => "goods_id【" . intval($goodsId) . "】库存扣减失败"]);
        }

        // 5. Redis扣减成功，先写入redis日志，然后再推送消息到mq队列（新方案），不用去循环扣减库存，而是直接扣减相应库存
        $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => json_encode($params, JSON_UNESCAPED_UNICODE), 'key' => $stockKey, 'val' => $stockNum, 'total_num' => $stockNum, 'operate_type' => 0]);//redis直接扣减成功了，但是数据库还没处理，所以redis日志状态仍然是处理中，mq还没有真正扣减
        $limit = 100;
        //剩下的可用数据，直接发布到mq队列进行消费，相当于直接重置可用数据字段
        if (!empty($bfNum)) {
            //写入mq队列逻辑
            for ($j = 0; $j <= ceil(intval($bfNum) / $limit); $j++) {
                //echo "第【【【【【【【【【【" . ($j + 1) . '】】】】】】】】】】】】次《《' . $limit . '》》条数据执行循环【开始】' . PHP_EOL;
                RabbitmqService::handleInterfaceData($queue_name, json_encode(['user_id' => $userId, 'i' => $j, 'goods_id' => $goodsId, 'key' => $stockKey, 'total_num' => $stockNum, 'val' => 1, 'redis_log_id' => $redis_log_id], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $goodsId . "，【redis日志ID】" . $redis_log_id . "【手动】redis库存扣减，同步数据库扣减【发布成功】，队列处理中......." . PHP_EOL, RabbitmqService::ONE);//用来做并发测试使用
            }
            //echo "总共{$bfNum}次数据执行循环【结束】-----------------------------------------------" . PHP_EOL;
        } else {
            //写入mq队列逻辑(只有单次的情况)
            RabbitmqService::handleInterfaceData($queue_name, json_encode(['user_id' => intval($userId), 'i' => 1, 'goods_id' => intval($goodsId), 'key' => $stockKey, 'total_num' => intval($stockNum), 'val' => intval($stockNum), 'redis_log_id' => $redis_log_id], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $goodsId . "，【redis日志ID】" . $redis_log_id . "【手动】redis库存扣减，同步数据库扣减【发布成功】，队列处理中......." . PHP_EOL, RabbitmqService::ONE);//单次扣减队列逻辑
        }

//        // 5. Redis扣减成功，先写入redis日志，然后再推送消息到mq队列（原始方案）
//        $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => json_encode($params, JSON_UNESCAPED_UNICODE), 'key' => $stockKey, 'val' => $stockNum, 'total_num' => $stockNum, 'operate_type' => 0]);//redis直接扣减成功了，但是数据库还没处理，所以redis日志状态仍然是处理中，mq还没有真正扣减
//        for ($i = 1; $i <= $stockNum; $i++) {
//            RabbitmqService::handleInterfaceData($queue_name, json_encode(['user_id' => intval($userId), 'i' => 1, 'goods_id' => intval($goodsId), 'key' => $stockKey, 'total_num' => intval($stockNum), 'val' => 1, 'redis_log_id' => $redis_log_id], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $goodsId . "，【redis日志ID】" . $redis_log_id . "【手动】redis库存扣减，同步数据库扣减【发布成功】，队列处理中......." . PHP_EOL, RabbitmqService::ONE);
//        }

        // 6. 前端立即返回成功（队列排队处理中，有可能立即执行）
        return json([
            'code' => 0,
            'msg' => '库存扣减消息队列处理中。。。。。。',
            'data' => [
                'queue_status' => 'pending',
                'estimated_time' => 5  // 预计5秒内完成
            ]
        ]);

    }


    /**
     * @return \think\response\Json
     * @author foxme
     * @date 2026/6/26 15:08
     * Description: 增加库存
     */
    public function incStock()
    {

        $queue_name = RabbitmqService::SYNC_INCR_STOCK_PUBLISHER;
        $is_produce = RabbitmqService::ONE;

        // 1. 参数校验
        $params = input('post.');

        $validate = Validate::rule([
            'user_id' => 'number|unique:users',
            'goods_id' => 'require|number',
            'num' => 'number|min:1',
        ]);

        if (!$validate->check($params)) {
            return json(['code' => 400, 'msg' => $validate->getError()]);
        }

        $userId = empty($params['user_id']) ? 1 : $params['user_id'];//如果为空，则暂时默认固定为超管用户ID为1
        $goodsId = $params['goods_id'];
        $stockNum = $params['num'];

        // redis键值
        $stockKey = RedisService::$flushAllStockKey . RedisService::$connectStr . $goodsId;

        $product_res = Db::name('goods')->field(['goods_id', 'sku', 'stock_warehouse_id'])->where('goods_id', $goodsId)->findOrEmpty();//查询商品数据，包含仓库ID和goods_id
        if (empty($product_res['stock_warehouse_id'])) {
            return json(['code' => 400, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】查询不到【所属仓库】"]);
        }

//        $stock_warehouse_data = Db::name('stock_warehouse')->where('id', intval($product_res['stock_warehouse_id']))->field(['goods_num', 'occupy', 'zt', 'backups_num', 'warning_num', 'original_quantity', 'freeze_quantity', 'pre_occupy'])->findOrEmpty();
//        if (empty($stock_warehouse_data['goods_num'])) {
//            return json(['code' => 400, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】【已上架库存为0】或查询不到【已上架库存】"]);
//        }
//
//        //计算真实可用库存
//        $stock_warehouse_data['true_num'] = floor(abs(intval($stockNum)) - abs(intval($stock_warehouse_data['occupy'])) - abs(intval($stock_warehouse_data['freeze_quantity'])));//可用库存=总库存（备货+拣货+冻结） -  占用 - 冻结
//        if (empty($stock_warehouse_data['true_num']) || ($stock_warehouse_data['true_num'] < 0)) {
//            return json(['code' => 400, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】【可用库存为0或已缺货】"]);
//        }

        //剩下的可用数据，直接发布到mq队列进行消费
        if ((!empty($stock_warehouse_data['true_num']) && ($stock_warehouse_data['true_num'] > 0)) || (abs(intval($stockNum)) > 0)) {
            //写入mq队列逻辑
            RabbitmqService::handleInterfaceData($queue_name, json_encode(['goods_id' => abs(intval($product_res['goods_id'])), 'true_stock' => abs(intval($stockNum)), 'redis_key' => $stockKey], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $product_res['goods_id'] . "，【商品sku】" . $product_res['sku'] . "【手动】生产新增库存【发布成功】，队列处理中......." . PHP_EOL, $is_produce);
            //return json(['code' => 0, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】，可用库存：【" . abs(intval($stockNum)) . "】，Redis热键：《《《《《" . $stockKey . "》》》》》【手动】新增成功发布成功，队列处理中。。。。。。"]);
            return json(['code' => 0, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】，可用库存：【" . abs(intval($stockNum)) . "】，【手动】新增成功发布成功，队列处理中。。。。。。"]);
        }

    }


    /**
     * @return \think\response\Json
     * @author foxme
     * @date 2026/6/26 18:51
     * Description: 获取指定redis键值
     */
    public function getStock()
    {
        // 1. 参数校验
        $params = input('get.');

        $validate = Validate::rule([
            'user_id' => 'number|unique:users',
            'goods_id' => 'require|number',
        ]);

        if (!$validate->check($params)) {
            return json(['code' => 400, 'msg' => $validate->getError()]);
        }

        $userId = empty($params['user_id']) ? 1 : $params['user_id'];//如果为空，则暂时默认固定为超管用户ID为1
        $goodsId = $params['goods_id'];

        // redis键值
        $stockKey = RedisService::$flushAllStockKey . RedisService::$connectStr . $goodsId;

        $product_res = Db::name('goods')->field(['goods_id', 'sku', 'stock_warehouse_id'])->where('goods_id', $goodsId)->findOrEmpty();//查询商品数据，包含仓库ID和goods_id
        if (empty($product_res['stock_warehouse_id'])) {
            return json(['code' => 400, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】查询不到【所属仓库】"]);
        }

        $stockNum = RedisService::getStock($stockKey) ?? 0;

        $redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => json_encode($params, JSON_UNESCAPED_UNICODE), 'key' => $stockKey, 'val' => $stockNum, 'total_num' => $stockNum, 'operate_type' => 3]);//redis直接查询库存

        //直接返回读取到的redis键值
        return json(['code' => 0, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】获取库存成功！", 'data' => $stockNum]);

    }


    /**
     * @return \think\response\Json
     * @author foxme
     * @date 2026/6/26 18:59
     * Description: 删除指定redis键值
     */
    public function delStock()
    {
        $queue_name = RabbitmqService::SYNC_DEL_STOCK_PUBLISHER;
        $is_produce = RabbitmqService::ONE;

        // 1. 参数校验
        $params = input('post.');

        $validate = Validate::rule([
            'user_id' => 'number|unique:users',
            'goods_id' => 'require|number',
        ]);

        if (!$validate->check($params)) {
            return json(['code' => 400, 'msg' => $validate->getError()]);
        }

        $userId = empty($params['user_id']) ? 1 : $params['user_id'];//如果为空，则暂时默认固定为超管用户ID为1
        $goodsId = $params['goods_id'];

        // redis键值
        $stockKey = RedisService::$flushAllStockKey . RedisService::$connectStr . $goodsId;

        $product_res = Db::name('goods')->field(['goods_id', 'sku', 'stock_warehouse_id'])->where('goods_id', $goodsId)->findOrEmpty();//查询商品数据，包含仓库ID和goods_id
        if (empty($product_res['stock_warehouse_id'])) {
            return json(['code' => 400, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】查询不到【所属仓库】"]);
        }

        //直接返回读取到的redis键值
        $stockNum = RedisService::getStock($stockKey) ?? 0;

        //$redis_log_id = RedisService::logData(['user_id' => $userId, 'goods_id' => $goodsId, 'payload' => json_encode($params, JSON_UNESCAPED_UNICODE), 'key' => $stockKey, 'val' => $stockNum, 'total_num' => $stockNum, 'operate_type' => 4]);//redis删除库存

        RabbitmqService::handleInterfaceData($queue_name, json_encode(['goods_id' => abs(intval($product_res['goods_id'])), 'true_stock' => abs(intval($stockNum)), 'redis_key' => $stockKey], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $product_res['goods_id'] . "，【商品sku】" . $product_res['sku'] . "【手动】删除库存【发布成功】，队列处理中......." . PHP_EOL, $is_produce);
        //return json(['code' => 0, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】，可用库存：【" . abs(intval($stockNum)) . "】，Redis热键：《《《《《" . $stockKey . "》》》》》【手动】删除库存队列发布成功，队列处理中。。。。。。"]);
        return json(['code' => 0, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】，可用库存：【" . abs(intval($stockNum)) . "】，【手动】删除库存队列发布成功，队列处理中。。。。。。"]);

    }


    /**
     * @return \think\response\Json
     * @throws \Exception
     * @author foxme
     * @date 2026/6/26 18:41
     * Description: 根据指定redis键设置库存
     */
    public function setStock()
    {
        $queue_name = RabbitmqService::SYNC_STOCK_PUBLISHER;
        $is_produce = RabbitmqService::ONE;

        // 1. 参数校验
        $params = input('post.');

        $validate = Validate::rule([
            'user_id' => 'number|unique:users',
            'goods_id' => 'require|number',
            'num' => 'number|min:1',
            'bf_num' => 'number|min:1',
        ]);

        if (!$validate->check($params)) {
            return json(['code' => 400, 'msg' => $validate->getError()]);
        }

        $userId = empty($params['user_id']) ? 1 : intval($params['user_id']);//如果为空，则暂时默认固定为超管用户ID为1
        $goodsId = intval($params['goods_id']);//商品ID
        $stockNum = intval($params['num']);//需要操作的库存数量
        $bfNum = empty($params['bf_num']) ? 0 : intval($params['bf_num']);//并发的数量

        // redis键值
        $stockKey = RedisService::$flushAllStockKey . RedisService::$connectStr . $goodsId;

        $product_res = Db::name('goods')->field(['goods_id', 'sku', 'stock_warehouse_id'])->where('goods_id', $goodsId)->findOrEmpty();//查询商品数据，包含仓库ID和goods_id
        if (empty($product_res['stock_warehouse_id'])) {
            return json(['code' => 400, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】查询不到【所属仓库】"]);
        }
        $limit = 100;
        //剩下的可用数据，直接发布到mq队列进行消费，相当于直接重置可用数据字段

//            if (!empty($bfNum)) {
//                //写入mq队列逻辑
//                for ($j = 0; $j <= ceil(intval($bfNum) / $limit); $j++) {
//                    //echo "第【【【【【【【【【【" . ($j + 1) . '】】】】】】】】】】】】次《《' . $limit . '》》条数据执行循环【开始】' . PHP_EOL;
//                    RabbitmqService::handleInterfaceData($queue_name, json_encode(['goods_id' => abs(intval($product_res['goods_id'])), 'true_stock' => 1, 'redis_key' => $stockKey], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $product_res['goods_id'] . "，【商品sku】" . $product_res['sku'] . "【手动】生产设置库存【发布成功】，队列处理中......." . PHP_EOL, $is_produce);//用来做并发测试使用
//                }
//                //echo "总共{$bfNum}次数据执行循环【结束】-----------------------------------------------" . PHP_EOL;
//            } else {
//                //写入mq队列逻辑(只有单次的情况)
//                RabbitmqService::handleInterfaceData($queue_name, json_encode(['goods_id' => abs(intval($product_res['goods_id'])), 'true_stock' => abs(intval($stockNum)), 'redis_key' => $stockKey], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $product_res['goods_id'] . "，【商品sku】" . $product_res['sku'] . "【手动】生产设置库存【发布成功】，队列处理中......." . PHP_EOL, $is_produce);
//            }

        RabbitmqService::handleInterfaceData($queue_name, json_encode(['goods_id' => abs(intval($product_res['goods_id'])), 'true_stock' => abs(intval($stockNum)), 'redis_key' => $stockKey], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $product_res['goods_id'] . "，【商品sku】" . $product_res['sku'] . "【手动】生产设置库存【发布成功】，队列处理中......." . PHP_EOL, $is_produce);
        //return json(['code' => 0, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】，可用库存：【" . abs(intval($stockNum)) . "】，Redis热键：《《《《《" . $stockKey . '&&' . $product_res['goods_id'] . "》》》》》【手动】设置库存队列发布成功，队列处理中。。。。。。"]);
        return json(['code' => 0, 'msg' => "sku【" . strval($product_res['sku']) . "】，goods_id【" . intval($product_res['goods_id']) . "】，可用库存：【" . abs(intval($stockNum)) . "】，【手动】设置库存队列发布成功，队列处理中。。。。。。"]);
    }


    /**
     * @param $userId
     * @return bool
     * @author foxme
     * @date 2026/6/26 12:13
     * Description: 令牌桶限流算法
     */
    private function checkRateLimit($userId)
    {
        $key = "rate_limit:{$userId}";
        $rate = 10;   // 每秒10个令牌
        $capacity = 50;  // 桶容量50

        $now = microtime(true);
        $tokens = RedisService::getStock($key);
        if ($tokens === false) {
            // 初始化桶
            RedisService::setStock($key, $capacity - 1);
            RedisService::setExpire($key, 1);
            return false;
        }

        $lastTime = RedisService::getStock("{$key}:time") ?: $now;
        $delta = $now - $lastTime;
        $newTokens = min($capacity, $tokens + $delta * $rate);

        if ($newTokens < 1) {
            return true;  // 被限流
        }

        // 消耗一个令牌
        RedisService::setStock($key, $newTokens - 1);
        RedisService::setStock("{$key}:time", $now);
        return false;
    }


}