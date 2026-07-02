<?php

return [
    'client_private_key_path' => app()->getRootPath() . 'cert/client_private_key.pem',//客户端私钥
    'client_public_key_path' => app()->getRootPath() . 'cert/client_public_key.pem', // 客户端公钥
    'server_private_key_path' => app()->getRootPath() . 'cert/server_private_key.pem',//服务端私钥
    'server_public_key_path' => app()->getRootPath() . 'cert/server_public_key.pem', // 服务端公钥
    // 客户端公钥列表（用于加密响应给客户端）
    'client_public_keys' => [
        'client_oms' => file_get_contents(app()->getRootPath() . 'cert/client_oms/public_key.pem'),//oms订单系统
        'client_wms' => file_get_contents(app()->getRootPath() . 'cert/client_wms/public_key.pem'),//wms仓库系统
        'client_pda' => file_get_contents(app()->getRootPath() . 'cert/client_pda/public_key.pem'),//仓库pda系统
        'client_erp' => file_get_contents(app()->getRootPath() . 'cert/client_erp/public_key.pem'),//中台erp系统
        'client_stock' => file_get_contents(app()->getRootPath() . 'cert/client_stock/public_key.pem'),//仓库库存系统
        'client_customer' => file_get_contents(app()->getRootPath() . 'cert/client_customer/public_key.pem'),//客户系统
        'client_user' => file_get_contents(app()->getRootPath() . 'cert/client_user/public_key.pem'),//内部用户系统
    ],
    // 客户端私钥列表（用于解密响应给客户端）
    'client_private_keys' => [
        'client_oms' => file_get_contents(app()->getRootPath() . 'cert/client_oms/private_key.pem'),//oms订单系统
        'client_wms' => file_get_contents(app()->getRootPath() . 'cert/client_wms/private_key.pem'),//wms仓库系统
        'client_pda' => file_get_contents(app()->getRootPath() . 'cert/client_pda/private_key.pem'),//仓库pda系统
        'client_erp' => file_get_contents(app()->getRootPath() . 'cert/client_erp/private_key.pem'),//中台erp系统
        'client_stock' => file_get_contents(app()->getRootPath() . 'cert/client_stock/private_key.pem'),//仓库库存系统
        'client_customer' => file_get_contents(app()->getRootPath() . 'cert/client_customer/private_key.pem'),//客户系统
        'client_user' => file_get_contents(app()->getRootPath() . 'cert/client_user/private_key.pem'),//内部用户系统
    ],
];