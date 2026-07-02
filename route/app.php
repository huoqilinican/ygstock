<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liupeng <foxmentsnake007@foxmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;

Route::get('think', function () {
    return '欢迎来到阳关科技库存系统!';
});

Route::post('decrStock', 'OperateStockController/decrStock');
Route::post('incStock', 'OperateStockController/incStock');
Route::post('setStock', 'OperateStockController/setStock');
Route::get('getStock', 'OperateStockController/getStock');
Route::post('delStock', 'OperateStockController/delStock');
Route::post('rpc', 'RpcServerController/handle');
