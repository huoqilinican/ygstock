<?php

namespace app\model;

use think\Model;

class RabbitmqLog extends Model
{

    protected $table = 'snake_rabbitmq_log';//表名


    public static function handleData($is_produce = 1, $name = 'default', $data = '', $msg = '')
    {
        return [
            'is_produce' => $is_produce,
            'name' => $name,
            'data' => $data,
            'msg' => $msg,
            'created_at' => time(),
            'updated_at' => time(),
        ];
    }


    /**
     * @param $data
     * Created by 刘朋.
     * User: 刘朋
     * Date: 2025/3/4
     * Time: 11:18
     * 写入rabbitmq日志
     */
    public static function log($data)
    {
        //写入数据库
        (new self())->insert($data);
    }
}
