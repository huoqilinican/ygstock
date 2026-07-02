<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'flush:stock' => 'app\command\FlushStock',
        'sync:stock' => 'app\command\SyncStock',
        'swoole:server' => 'app\command\SwooleServer',
        'mq:stock_publisher' => 'app\command\RabbitmqStockPublisher',
        'mq:stock_consume' => 'app\command\RabbitmqStockConsume',
        'mq:incr_stock_publisher' => 'app\command\RabbitmqIncrStockPublisher',
        'mq:incr_stock_consume' => 'app\command\RabbitmqIncrStockConsume',
        'mq:decr_stock_publisher' => 'app\command\RabbitmqDecrStockPublisher',
        'mq:decr_stock_consume' => 'app\command\RabbitmqDecrStockConsume',
        'mq:last_decr_stock_consume' => 'app\command\RabbitmqLastDecrStockConsume',
        'mq:del_stock_consume' => 'app\command\RabbitmqDelStockConsume',
    ],
];
