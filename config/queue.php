<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

return [
    'default' => 'sync',
    'connections' => [
        'sync' => [
            'type' => 'sync',
        ],
        'database' => [
            'type' => 'database',
            'queue' => 'default',
            'table' => 'jobs',
            'connection' => null,
        ],
        'redis' => [
            'queue' => 'default',
            'type' => 'redis',      //类型
            'host' => '127.0.0.1',  // Redis 服务器地址
            'port' => 9508,         // Redis 服务端口
            'password' => '',           // Redis 密码，没有则留空
            'select' => 0,            // 使用的 Redis 数据库编号（0-15）
            'timeout' => 0,            // 连接超时时间（秒），0 表示不限制
            'expire' => 0,            // 默认缓存有效期（秒），0 表示永久
            'prefix' => '',           // 缓存键前缀，用于避免不同应用键名冲突
            'persistent' => true,        // 是否启用长连接，高并发时建议开启[citation:4][citation:6]
        ],
        'rabbitmq' => [
            'driver' => 'rabbitmq',
            'host' => env('RABBITMQ_HOST', 'localhost'),
            'port' => env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            'queue' => env('RABBITMQ_QUEUE', 'default'),
            'exchange' => [
                'name' => env('RABBITMQ_EXCHANGE', 'default'),
                'type' => env('RABBITMQ_EXCHANGE_TYPE', 'direct'), // direct, topic, fanout, etc.
            ],
            'durable' => env('RABBITMQ_DURABLE', true), // 持久化
            'retry_after' => 90, // 重试时间间隔（秒）
            'block_on_connect' => true, // 是否在连接时阻塞，默认为 true。
            'heartbeat' => 0, // 设置心跳间隔（秒），0 为不发送心跳。根据网络环境调整。
            'auto_delete' => false,
            'internal' => false,
            'no_wait' => false,
        ],
    ],
    'failed' => [
        'type' => 'none',
        'table' => 'failed_jobs',
    ],
];
