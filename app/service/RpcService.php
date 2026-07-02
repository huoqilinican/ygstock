<?php


namespace app\service;


class RpcService
{

    const OMS_CLIENT_ID = 'client_oms';//订单clientId
    const WMS_CLIENT_ID = 'client_wms';//仓库clientId
    const PDA_CLIENT_ID = 'client_pda';//pda终端clientId
    const ERP_CLIENT_ID = 'client_erp';//中台clientId
    const STOCK_CLIENT_ID = 'client_stock';//库存clientId
    const CUSTOMER_CLIENT_ID = 'client_customer';//客户clientId
    const USER_CLIENT_ID = 'client_user';//内部用户clientId

    const OMS_RPC_API_URL = 'http://127.0.0.1:8001/rpc';
    const WMS_RPC_API_URL = 'http://127.0.0.1:8002/rpc';
    const PDA_RPC_API_URL = 'http://127.0.0.1:8003/rpc';
    const ERP_RPC_API_URL = 'http://127.0.0.1:8004/rpc';
    const STOCK_RPC_API_URL = 'http://127.0.0.1:8688/rpc';
    const CUSTOMER_RPC_API_URL = 'http://127.0.0.1:8005/rpc';
    const USER_RPC_API_URL = 'http://127.0.0.1:8006/rpc';

    /**
     * @var string[]
     * @author foxme
     * @date 2026/7/2 11:54
     * Description: clientId与rpc的url链接关系
     */
    public static $clientApiUrlData = [
        self::OMS_CLIENT_ID => self::OMS_RPC_API_URL,
        self::WMS_CLIENT_ID => self::WMS_RPC_API_URL,
        self::PDA_CLIENT_ID => self::PDA_RPC_API_URL,
        self::ERP_CLIENT_ID => self::ERP_RPC_API_URL,
        self::STOCK_CLIENT_ID => self::STOCK_RPC_API_URL,
        self::CUSTOMER_CLIENT_ID => self::CUSTOMER_RPC_API_URL,
        self::USER_CLIENT_ID => self::USER_RPC_API_URL,
    ];

    public function hello($name)
    {
        return ['code' => 0, 'data' => 'hello,' . $name, 'time' => date('Y-m-d H:i:s')];
    }

    public function getUser($id)
    {
        // 模拟业务逻辑
        return [
            'id' => $id,
            'name' => '张三',
            'email' => 'zhangsan@example.com',
            'time' => date('Y-m-d H:i:s')
        ];
    }

    public function updateUser($id, $data)
    {
        // 模拟更新
        return ['status' => 'success', 'id' => $id];
    }

}