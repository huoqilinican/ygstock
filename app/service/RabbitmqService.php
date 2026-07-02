<?php

namespace app\service;

use app\model\RabbitmqLog;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitmqService
{
    const ZERO = 0;//是否消费
    const ONE = 1;//是否生产
    const SYNC_STOCK_PUBLISHER = 'sync_stock_publisher';//同步库存设置生产队列
    const SYNC_STOCK_CONSUME = 'sync_stock_consume';//同步库存设置消费队列
    const SYNC_INCR_STOCK_PUBLISHER = 'sync_incr_stock_publisher';//异步新增库存生产者
    const SYNC_INCR_STOCK_CONSUME = 'sync_incr_stock_consume';//异步新增库存消费者
    const SYNC_DECR_STOCK_PUBLISHER = 'sync_decr_stock_publisher';//异步减少库存生产者
    const SYNC_DECR_STOCK_CONSUME = 'sync_decr_stock_consume';//异步减少库存消费者
    const SYNC_LAST_DECR_STOCK_CONSUME = 'sync_last_decr_stock_consume';//异步真正处理减少库存消费者
    const SYNC_DEL_STOCK_PUBLISHER = 'sync_del_stock_publisher';//同步库存删除队列
    const SYNC_DEL_STOCK_CONSUME = 'sync_del_stock_consume';//同步库存删除消费队列


    /**
     * @var string[]
     * @author foxme
     * @date 2026/6/17 17:39
     * Description: 路由键数组
     */
    public static $rabbitmq_routing_key_arr = [
        self::SYNC_STOCK_PUBLISHER => 'sync_stock_publisher_routing_key',//同步库存设置生产队列路由键
        self::SYNC_STOCK_CONSUME => 'sync_stock_consume_routing_key',//同步库存设置消费队列路由键
        self::SYNC_INCR_STOCK_PUBLISHER => 'sync_incr_stock_publisher_routing_key',//异步新增库存生产者路由键
        self::SYNC_INCR_STOCK_CONSUME => 'sync_incr_stock_consume_routing_key',//异步新增库存消费者路由键
        self::SYNC_DECR_STOCK_PUBLISHER => 'sync_decr_stock_publisher_routing_key',//异步减少库存生产者路由键
        self::SYNC_DECR_STOCK_CONSUME => 'sync_decr_stock_consume_routing_key',//异步减少库存消费者路由键
        self::SYNC_LAST_DECR_STOCK_CONSUME => 'sync_last_decr_stock_consume_routing_key',//异步真正处理减少库存消费者路由键
        self::SYNC_DEL_STOCK_PUBLISHER => 'sync_del_stock_publisher_routing_key',//同步库存删除队列
        self::SYNC_DEL_STOCK_CONSUME => 'sync_del_stock_consume_routing_key',//同步库存删除消费队列
    ];


    /**
     * @return AMQPStreamConnection
     * @throws \Exception
     * @author foxme
     * @date 2026/6/17 17:40
     * Description: 生成公共连接对象
     */
    private static function getConnect()
    {
        $config = [
            'host' => env('RABBITMQ_HOST', '127.0.0.1'),
            'port' => env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            //    'heartbeat' => 90,
        ];
        return new AMQPStreamConnection($config['host'], $config['port'], $config['user'], $config['password'], $config['vhost'],
            false, 'AMQPLAIN', null, 'en_US'
//            10,      // 连接超时（秒）,默认3秒
//            10,       // 读写超时（秒）,默认3秒
//            null,
//            false,
//            $config['heartbeat']， //可配置心跳时间
        );
    }


    /**
     * @param $queue_name
     * @return int|mixed
     * @author foxme
     * @date 2026/6/17 17:40
     * Description: 获取指定mq队列名称的消息数量
     */
    public static function getMessageNum($queue_name)
    {
        // RabbitMQ获取指定队列名称的插件的URL（根据你的安装配置调整）
        $rabbitmq_api_url = env('RABBITMQ_HOST', '127.0.0.1') . ':15672/api/queues/%2F/' . $queue_name;

        // RabbitMQ管理界面的用户名和密码
        $username = env('RABBITMQ_USER', 'guest');
        $password = env('RABBITMQ_PASSWORD', 'guest');


        // 初始化cURL会话
        $ch = curl_init();


        // 设置cURL选项
        curl_setopt($ch, CURLOPT_URL, $rabbitmq_api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");


        // 执行cURL会话并获取响应
        $response = curl_exec($ch);
        $data = json_decode($response, true);

        return empty($data['messages_ready']) ? 0 : $data['messages_ready'];

    }


    /**
     * @param $queue
     * @param $exchange
     * @param $routing_key
     * @param array $messageBody
     * @param int $priority
     * @param int $delayMs
     * @throws \Exception
     * @author foxme
     * @date 2026/6/17 17:40
     * Description: （生产者）批量发送消息
     */
    public static function batchPush($queue, $exchange, $routing_key, $messageBody = [], $priority = 0, $delayMs = 54600000)
    {
        //获取连接
        $connection = self::getConnect();

        //构建通道（mq的数据存储与获取是通过通道进行数据传输的）
        $channel = $connection->channel();
        // 启用发布确认（Publisher Confirms）
        $channel->confirm_select(); // 开启确认模式

        if (empty($priority) && !in_array($queue, [self::SYNC_STOCK_CONSUME])) {

            //监听数据,成功
            $channel->set_ack_handler(function (AMQPMessage $message) {
                return '数据写入成功';
            });

            //监听数据,失败
            $channel->set_nack_handler(function (AMQPMessage $message) {
                return '数据写入失败';
            });

            //延迟队列声明，指定交换机，若是路由的名称不匹配不会把数据放入队列中
            if ($exchange == self::SYNC_STOCK_CONSUME) {
                // 延时交换机逻辑，声明 x-delayed-message 类型的 Exchange，通过 arguments 指定真实路由类型
                $channel->exchange_declare(
                    $exchange,
                    'x-delayed-message',   // 自定义类型
                    false,                 // passive
                    true,                  // durable
                    false,                 // auto_delete
                    false,                 // internal
                    false,                 // nowait
                    new AMQPTable(['x-delayed-type' => 'direct'])  // 延时到期后按 direct 路由
                );
                //声明一个队列
                $channel->queue_declare($queue, false, true, false, false);
            } else {
                //普通交换机
                $channel->exchange_declare($exchange, 'direct', false, true, false);
                //声明一个队列
                $channel->queue_declare($queue, false, true, false, false, false);//x-max-priority范围是0-255
            }

            //队列和交换器绑定/绑定队列和类型
            $channel->queue_bind($queue, $exchange, $routing_key);

            // 发送消息，通过 header 的 x-delay 指定延时毫秒数

            foreach ($messageBody as $message) {
                if ($exchange == self::SYNC_STOCK_CONSUME) {
                    //延迟交换机逻辑
                    $config = [
                        'content_type' => 'text/plain',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        'application_headers' => new AMQPTable(['x-delay' => $delayMs]),//延迟时间，表示多少毫秒
                    ];
                } else {
                    //普通交换机逻辑
                    $config = [
                        'content_type' => 'text/plain',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    ];
                }

                $channel->batch_basic_publish(new AMQPMessage($message, $config), $exchange, $routing_key, false, false, true);

            }

            $channel->publish_batch(); // 触发批量发送

            //关闭消息推送资源
            $channel->close();

            //关闭mq资源
            $connection->close();


        } else {
            // 声明支持优先级的队列
            $channel->queue_declare(
                $queue,    // 队列名
                false,         // 被动模式（false表示如果不存在则创建）
                true,          // 持久化
                false,         // 独占队列
                false,         // 自动删除
                false,         // 现在ait
                new AMQPTable([
                    'x-max-priority' => 10  // 设置最大优先级为10
                ])
            );

            foreach ($messageBody as $message) {

                $config = [
                    'content_type' => 'text/plain',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,//表示持久化
                    'priority' => $priority,
                ];

                $channel->batch_basic_publish(new AMQPMessage($message, $config), '', $queue);

            }

            $channel->publish_batch(); // 触发批量发送

            //关闭消息推送资源
            $channel->close();
            //关闭mq资源
            $connection->close();

        }
    }


    /**
     * @param $queue
     * @param $exchange
     * @param $routing_key
     * @param $messageBody
     * @param int $priority
     * @param int $delayMs
     * @return string
     * @throws \Exception
     * @author foxme
     * @date 2026/6/17 17:44
     * Description: （生产者）单个发送消息，如果是延迟队列，则单位是毫秒），默认给15个小时，可以根据业务逻辑更改
     */
    public static function push($queue, $exchange, $routing_key, $messageBody, $priority = 0, $delayMs = 54600000)
    {
        //获取连接
        $connection = self::getConnect();
        //构建通道（mq的数据存储与获取是通过通道进行数据传输的）
        $channel = $connection->channel();

        if (empty($priority) && !in_array($queue, [self::SYNC_STOCK_CONSUME])) {
            //非优先级队列声明
            //监听数据,成功
            $channel->set_ack_handler(function (AMQPMessage $message) {
                return '数据写入成功';
            });

            //监听数据,失败
            $channel->set_nack_handler(function (AMQPMessage $message) {
                return '数据写入失败';
            });

            //延时交换机逻辑声明
            if ($exchange == self::SYNC_STOCK_CONSUME) {

                // 声明 x-delayed-message 类型的 Exchange，通过 arguments 指定真实路由类型
                $channel->exchange_declare(
                    $exchange,
                    'x-delayed-message',   // 自定义类型
                    false,                 // passive
                    true,                  // durable
                    false,                 // auto_delete
                    false,                 // internal
                    false,                 // nowait
                    new AMQPTable(['x-delayed-type' => 'direct'])  // 延时到期后按 direct 路由
                );
                //声明一个队列
                $channel->queue_declare($queue, false, true, false, false);

            } else {

                //指定普通交换机，若是路由的名称不匹配不会把数据放入队列中
                $channel->exchange_declare($exchange, 'direct', false, true, false);
                //声明一个队列
                $channel->queue_declare($queue, false, true, false, false, false);//x-max-priority范围是0-255

            }

            //队列和交换机绑定/绑定队列和类型
            $channel->queue_bind($queue, $exchange, $routing_key);

            if ($exchange == self::SYNC_STOCK_CONSUME) {
                //延时交换机逻辑
                $config = [
                    'content_type' => 'text/plain',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'application_headers' => new AMQPTable(['x-delay' => $delayMs]),
                ];
            } else {
                //普通交换机逻辑
                $config = [
                    'content_type' => 'text/plain',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
                ];
            }

            //实例化消息推送类
            $message = new AMQPMessage($messageBody, $config);

            //消息推送到路由名称为$exchange的队列当中
            $channel->basic_publish($message, $exchange, $routing_key, false, false, true);

            //监听写入
            $channel->wait_for_pending_acks();

            //return '生产者已操作'.PHP_EOL;

            //关闭消息推送资源
            $channel->close();

            //关闭mq资源
            $connection->close();

            return '消息推送成功！';
        } else {
            // 声明优先级的队列
            $channel->queue_declare(
                $queue,    // 队列名
                false,         // 被动模式（false表示如果不存在则创建）
                true,          // 持久化
                false,         // 独占队列
                false,         // 自动删除
                false,         // 现在ait
                new AMQPTable([
                    'x-max-priority' => 10  // 设置最大优先级为10
                ])
            );
            // 发送不同优先级的消息
            $msg = new AMQPMessage(
                $messageBody,
                [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,//表示持久化
                    'priority' => $priority
                ]
            );
            $channel->basic_publish($msg, '', $queue);
            //关闭消息推送资源
            $channel->close();
            //关闭mq资源
            $connection->close();
        }
    }

    /**
     * @param $queue
     * @param $callback
     * @throws \AMQPConnectiSYNC_STOCK_PUBLISHERxception
     * @throws \ErrorException
     * @author foxme
     * @date 2026/6/17 17:50
     * Description: 消费者：取出消息进行消费，并返回，最新版
     */
    public static function pop($queue, $callback)
    {
        $retryCount = 2;
        while ($retryCount > 0) {
            try {

                $connection = self::getConnect();//建立连接

                $channel = $connection->channel();//构建消息通道

                if (in_array($queue, [self::SYNC_STOCK_CONSUME])) {
                    //声明一个优先级队列
                    $channel->queue_declare($queue, false, true, false, false, false,
                        new AMQPTable([
                            'x-max-priority' => 10  // 设置最大优先级为10
                        ]));
                } else {
                    if ($queue == self::SYNC_STOCK_CONSUME) {
                        $channel->queue_declare($queue, false, true, false, false);//声明延迟队列
                    } else {
                        $channel->queue_declare($queue, false, true, false, false, false);//声明普通队列
                    }
                }

                $channel->basic_qos(null, 1, null);// 设置每次只处理1条消息（确保优先级生效）

                // 调用 basic_consume
                $channel->basic_consume($queue, '', false, false, false, false, $callback);//直接消费，第四个参数表示自动确认，false表示手动确认

                // 保持消费者运行
                while ($channel->is_consuming()) {
                    $channel->wait();//保持长连接（如 CLI 模式），RabbitMQ默认消费确认超时时间为1800秒（30分钟）
                }

                $channel->close();//关闭频道

                $connection->close();//关闭连接

            } catch (\AMQPConnectionException $e) {
                if (stripos($e->getMessage(), 'heartbeat') !== false) {
                    $retryCount--;
                    sleep(5); // 等待后重试
                } else {
                    throw $e;
                }
            }
        }
    }


    /**
     * @param $exception
     * @return string
     * @author foxme
     * @date 2026/6/17 17:53
     * Description: 捕获异常信息
     */
    public static function failed($exception)
    {
        return '异常信息是：' . $exception->getMessage() . PHP_EOL;
    }


    /**
     * @param $queue_name
     * @param $param
     * @param $data
     * @param $msg
     * @param $is_produce
     * @param int $priority
     * @throws \Exception
     * @author foxme
     * @date 2026/6/18 19:05
     * Description: 队列生产和消费的公共处理接口
     */
    public static function handleInterfaceData($queue_name, $param, $data, $msg, $is_produce, $priority = 0)
    {
        //提前检测是否属于此队列数组
        self::$rabbitmq_routing_key_arr = self::handleRabbitmqParam($queue_name);
        self::push($queue_name, $queue_name, self::$rabbitmq_routing_key_arr[$queue_name], $param, $priority);
        self::insertData($is_produce, $queue_name, $data, $msg);
    }


    /**
     * @param $queue_name
     * @param $param
     * @param $data
     * @param $msg
     * @param $is_produce
     * @param int $priority
     * @throws \Exception
     * @author foxme
     * @date 2026/6/18 19:04
     * Description: 批量队列下发任务逻辑
     */
    public static function batchHandleInterfaceData($queue_name, $param, $data, $msg, $is_produce, $priority = 0)
    {
        self::$rabbitmq_routing_key_arr = self::handleRabbitmqParam($queue_name);
        self::batchPush($queue_name, $queue_name, self::$rabbitmq_routing_key_arr[$queue_name], $param, $priority);
        self::insertData($is_produce, $queue_name, $data, $msg);
    }


    /**
     * @param $is_produce
     * @param $queue_name
     * @param $data
     * @param $msg
     * @author foxme
     * @date 2026/6/18 19:04
     * Description: 写入mq日志操作
     */
    public static function insertData($is_produce, $queue_name, $data, $msg)
    {
        RabbitmqLog::log(self::logData($is_produce, $queue_name, $data, $msg));
    }


    /**
     * @param $is_produce
     * @param $queue_name
     * @param $data
     * @param $msg
     * @return array
     * @author foxme
     * @date 2026/6/18 19:05
     * Description: 生成队列日志数据
     */
    public static function logData($is_produce, $queue_name, $data, $msg)
    {
        return RabbitmqLog::handleData($is_produce, $queue_name, $data, $msg);
    }


    /**
     * @param $queue
     * @return array|string[]
     * @author foxme
     * @date 2026/6/22 16:11
     * Description: 公共处理路由键
     */
    public static function handleRabbitmqParam($queue)
    {
        $flip_arr = array_flip(self::$rabbitmq_routing_key_arr);
        if (!in_array($queue, $flip_arr)) {
            $new_queue_data = [$queue => $queue . '_routing_key'];
            return array_merge(self::$rabbitmq_routing_key_arr, $new_queue_data);
        }
        return self::$rabbitmq_routing_key_arr;
    }

}

