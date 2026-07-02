<?php

namespace app\model;

use think\Model;

class RedisResultLog extends Model
{

    protected $table = 'snake_redis_result_log';//表名


    /**
     * @param $param
     * @return array
     * @author foxme
     * @date 2026/6/23 17:11
     * Description: 处理表格数据
     */
    public static function handleData($param)
    {
        $data = [
            'redis_log_id' => empty($param['redis_log_id']) ? 1 : $param['redis_log_id'],
            'user_id' => empty($param['user_id']) ? 1 : $param['user_id'],
            'goods_id' => empty($param['goods_id']) ? 1 : $param['goods_id'],
            'key' => empty($param['redis_key']) ? '' : $param['redis_key'],
            'val' => empty($param['stock']) ? 0 : $param['stock'],
            'exception' => empty($param['exception']) ? '' : $param['exception'],
            'type' => empty($param['type']) ? 0 : $param['type'],
            'created_at' => time(),
            'updated_at' => time(),
        ];
        //写入数据库
        return self::log($data);
    }

    public static function log($data)
    {
        //写入数据库
        return (new self())->insertGetId($data);
    }


    /**
     * @param $redis_log_id
     * @return int
     * @author foxme
     * @date 2026/6/23 17:16
     * Description: 统计指定redis热键失败数据条数
     */
    public static function isFailData($redis_log_id)
    {
        return (new self())->where('redis_log_id', $redis_log_id)->where('type', 0)->count('result_id');
    }

    /**
     * @param $redis_log_id
     * @return int
     * @author foxme
     * @date 2026/6/23 17:17
     * Description: 统计指定redis热键成功数据条数
     */
    public static function isSuccessData($redis_log_id)
    {
        return (new self())->where('redis_log_id', $redis_log_id)->where('type', 1)->count('result_id');
    }
}
