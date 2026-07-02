<?php

namespace app\model;

use think\Model;

class RedisLog extends Model
{

    protected $table = 'snake_redis_log';//表名


    /**
     * @param $param
     * @return array
     * @author foxme
     * @date 2026/6/22 14:11
     * Description: 处理表格数据
     */
    public static function handleData($param)
    {
        $data = [
            'user_id' => empty($param['user_id']) ? 1 : $param['user_id'],
            'goods_id' => empty($param['goods_id']) ? 1 : $param['goods_id'],
            'key' => empty($param['key']) ? '' : $param['key'],
            'val' => empty($param['val']) ? 0 : $param['val'],
            'total_num' => empty($param['val']) ? 0 : $param['val'],
            'operate_type' => empty($param['operate_type']) ? 0 : $param['operate_type'],
            'version' => empty($param['version']) ? 'V1' : $param['version'],
            'status' => 0,//新增默认就是处理中
            'payload' => empty($param['payload']) ? null : (is_array($param['payload']) ? json_encode($param['payload'], JSON_UNESCAPED_UNICODE) : $param['payload']),
            'created_at' => time(),
            'updated_at' => time(),
        ];
        return self::log($data);
    }


    /**
     * @param $data
     * @author foxme
     * @date 2026/6/23 17:24
     * Description: 写入日志逻辑
     */
    public static function log($data)
    {
        //写入数据库
        return (new self())->insertGetId($data);
    }


    /**
     * @param $redis_log_id
     * @return RedisLog|array|mixed|Model
     * @author foxme
     * @date 2026/6/25 18:05
     * Description: 查询redis日志记录表的指定字段数据
     */
    public static function querySuccOrFailData($redis_log_id)
    {
        return (new self())->where('redis_id', $redis_log_id)->field(['success_num', 'fail_num', 'total_num'])->findOrEmpty();
    }

    /**
     * @param $redis_log_id
     * @param int $status
     * @author foxme
     * @date 2026/6/23 17:38
     * Description: 通过主键ID更新其状态值和请求数据
     */
    public static function updateStatus($redis_log_id, $status = 1)
    {
        (new self())->where('redis_id', $redis_log_id)->update(['status' => $status]);
    }
}
