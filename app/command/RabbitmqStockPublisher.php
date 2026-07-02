<?php

namespace app\command;

use app\service\RabbitmqService;
use app\service\RedisService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Db;

class RabbitmqStockPublisher extends Command
{
    protected function configure()
    {
        $this->setName('mq:stock_publisher')->setDescription('预热商品库存到Redis生产者')
            ->addArgument('redis_key', Argument::OPTIONAL, "redis热键");
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \think\db\exception\DbException
     * @author foxme
     * @date 2026/6/17 18:30
     * Description: 根据业务类型同步商品库存到mq队列逻辑（全量去刷）
     */
    protected function execute(Input $input, Output $output)
    {
        set_time_limit(0); // 不限制脚本执行时间
        $redis_key = $input->getArgument('redis_key');
        $redis_key = empty($redis_key) ? (RedisService::$flushAllStockKey) : $redis_key;//给个默认键值，没有设置就同步全量商品库存，一般是根据业务区分
        $limit = 500;//分页初始值暂时设置为500，可以更改
        $queue_name = RabbitmqService::SYNC_STOCK_PUBLISHER;
        $is_produce = RabbitmqService::ONE;
        // 记录开始时间
        $time_start = microtime(true);
        echo "\n【" . date('Y-m-d H:i:s') . "】批量生产同步商品库存写入任务【开始】执行\n";
        try {
            $total = Db::name('goods')->count('goods_id');
            for ($j = 0; $j <= ceil($total / $limit); $j++) {
                echo "第【【【【【【【【【【" . ($j + 1) . '】】】】】】】】】】】】次《《' . $limit . '》》条数据执行循环开始' . PHP_EOL;
                $where_sql = [
                    // ['stock_warehouse_id', '>', 0],
                    ['goods_id', '>', $j * $limit],
                    ['goods_id', '<=', (($j + 1) * $limit)],
                ];
                $product_res = Db::name('goods')->field(['goods_id', 'sku', 'stock_warehouse_id'])->where($where_sql)->select()->toArray();//查询商品数据，包含仓库ID和goods_id
                if (!empty($product_res)) {
                    foreach ($product_res as &$i) {
                        if (empty($i['stock_warehouse_id'])) {
                            echo "sku【" . strval($i['sku']) . "】，goods_id【" . intval($i['goods_id']) . "】查询不到【所属仓库】，已跳过。。。。。。" . PHP_EOL;
                            continue;
                        }
                        $stock_warehouse_data = [];//每次初始化商品库存数据，因为是以商品goods_id维度存储每一个商品的库存数据
                        $stock_warehouse_data['true_num'] = 0;//初始化真实可用库存为0
                        $stock_warehouse_data = Db::name('stock_warehouse')->where('id', intval($i['stock_warehouse_id']))->field(['goods_num', 'occupy', 'zt', 'backups_num', 'warning_num', 'original_quantity', 'freeze_quantity', 'pre_occupy'])->findOrEmpty();
                        if (!empty($stock_warehouse_data['goods_num'])) {
                            //计算真实可用库存
                            $stock_warehouse_data['true_num'] = floor(abs(intval($stock_warehouse_data['goods_num'])) - abs(intval($stock_warehouse_data['occupy'])) - abs(intval($stock_warehouse_data['freeze_quantity'])));//可用库存=总库存（备货+拣货+冻结） -  占用 - 冻结
                            if (!empty($stock_warehouse_data['true_num']) && ($stock_warehouse_data['true_num'] > 0)) {
                                //写入mq队列逻辑
                                RabbitmqService::handleInterfaceData($queue_name, json_encode(['goods_id' => abs(intval($i['goods_id'])), 'true_stock' => abs(intval($stock_warehouse_data['true_num'])), 'redis_key' => $redis_key . RedisService::$connectStr . $i['goods_id']], JSON_UNESCAPED_UNICODE), "【成功】", "【商品goods_id】" . $i['goods_id'] . "，【商品sku】" . $i['sku'] . "生产库存【发布成功】，队列处理中......." . PHP_EOL, $is_produce);
                                echo "sku【" . strval($i['sku']) . "】，goods_id【" . intval($i['goods_id']) . "】，可用库存：【" . $stock_warehouse_data['true_num'] . "】，Redis热键：《《《《《" . $redis_key . RedisService::$connectStr . $i['goods_id'] . "》》》》》队列发布成功！！！！！！" . PHP_EOL;
                            } else {
                                echo "sku【" . strval($i['sku']) . "】，goods_id【" . intval($i['goods_id']) . "】【可用库存为0或已缺货】已跳过。。。。。。。。。。。。。。。。。。。。。。。。。。。。。。。" . PHP_EOL;
                                continue;
                            }
                        } else {
                            echo "sku【" . strval($i['sku']) . "】，goods_id【" . intval($i['goods_id']) . "】【已上架库存为0】或查询不到【已上架库存】，已跳过。。。。。。。。" . PHP_EOL;
                            continue;
                        }
                    }
                    unset($i);
                } else {
                    echo "【" . date('Y-m-d H:i:s') . "】暂无满足同步库存条件的商品数据，请确保商品数据正常！\n";
                    continue;
                }
            }
        } catch (\Exception $e) {
            echo "【" . date('Y-m-d H:i:s') . "】错误信息是：【" . $e->getMessage() . "】\n";
        }
        echo "\n【" . date('Y-m-d H:i:s') . "】批量生产同步商品库存写入任务【结束】执行\n";
        // 记录结束时间
        $time_end = microtime(true);
        // 计算并打印执行时间
        $execution_time = ($time_end - $time_start);
        echo "\n【" . date('Y-m-d H:i:s') . "】脚本执行时间总共：【" . $execution_time . "】 秒。";
    }

}
