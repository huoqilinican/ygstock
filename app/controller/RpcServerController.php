<?php

namespace app\controller;

use app\BaseController;
use app\service\CryptoService;
use think\facade\Log;
use think\facade\Request;

class RpcServerController extends BaseController
{

//    /**
//     * @return \think\response\Json
//     * @author foxme
//     * @date 2026/7/1 15:55
//     * Description: 原始方案
//     */
//    public function handle()
//    {
//        try {
//            //1. 接收数据
//            $data = Request::post();
//            if (!$data) {
//                return json(['code' => 400, 'msg' => '缺少参数数据']);
//            }
//
//            // 2. 执行服务调用
//            $class = $data['class'] ?? '';   // 如：app\\service\\RpcService
//            $method = $data['method'] ?? '';
//            $params = $data['params'] ?? [];
//
//            if (!class_exists($class)) {
//                return json(['code' => 404, 'msg' => '服务不存在']);
//            }
//            $service = app($class);
//            if (!method_exists($service, $method)) {
//                return json(['code' => 405, 'msg' => '方法不存在']);
//            }
//
//            //3 . 请求参数
//            $result = call_user_func_array([$service, $method], $params);
//
//            // 判断返回类型
//            if ($result instanceof \think\response\Json) {
//                // 从 Json 对象中提取数据
//                $json_data = $result->getData();
//                $response = ['code' => 0, 'data' => $json_data];
//            } else {
//                // 普通数据直接返回
//                $response = ['code' => 0, 'data' => $result];
//            }
//
//           return json($response);
//        } catch (\Exception $e) {
//            Log::error('RPC调用异常: ' . $e->getMessage());
//            return json(['code' => 500, 'msg' => '系统错误']);
//        }
//    }


//    /**
//     * @return \think\response\Json
//     * @author foxme
//     * @date 2026/7/1 16:00
//     * Description: 第二套方案（纯 RSA加解密）
//     */
//    public function handle()
//    {
//        try {
//            //1. 接收加密数据
//            $encrypted = Request::post();
//            if (!$encrypted) {
//                return json(['code' => 400, 'msg' => '缺少参数数据']);
//            }
//
//            // 2. 用私钥解密请求数据
//            $jsonData = CryptoService::decryptByPrivateKey($encrypted);
//            if (!$jsonData) {
//                //Log::error('RSA解密失败');
//                return json(['code' => 401, 'msg' => '解密失败']);
//            }
//
//            $data = json_decode($jsonData, true);
//            if (!$data || !isset($data['class'], $data['method'])) {
//                return json(['code' => 402, 'msg' => '请求格式错误']);
//            }
//
//            // 3. 执行服务调用
//            $class = $data['class'] ?? '';   // 如：app\\service\\RpcService
//            $method = $data['method'] ?? '';
//            $params = $data['params'] ?? [];
//
//            if (!class_exists($class)) {
//                return json(['code' => 404, 'msg' => '服务不存在']);
//            }
//            $service = app($class);
//            if (!method_exists($service, $method)) {
//                return json(['code' => 405, 'msg' => '方法不存在']);
//            }
//
//            $result = call_user_func_array([$service, $method], $params);
//
//            // 判断返回类型
//            if ($result instanceof \think\response\Json) {
//                // 从 Json 对象中提取数据
//                $json_data = $result->getData();
//                $response = ['code' => 0, 'data' => $json_data];
//            } else {
//                // 普通数据直接返回
//                $response = ['code' => 0, 'data' => $result];
//            }
//
//            // 4. 用私钥加密响应数据（防止中间人篡改）
//            $encryptedResponse = CryptoService::encryptByPrivateKey(json_encode($response));
//
//            if (!$encryptedResponse) {
//                Log::error('响应加密失败');
//                return json(['code' => 500, 'msg' => '加密响应失败']);
//            }
//
//            return json(['data' => $encryptedResponse]);
//        } catch (\Exception $e) {
//            Log::error('RPC调用异常: ' . $e->getMessage());
//            return json(['code' => 500, 'msg' => '系统错误']);
//        }
//    }


    public function handle()
    {
        try {
            // 1. 接收加密数据
            $encryptedKey = Request::post('encrypted_key');
            $encryptedData = Request::post('encrypted_data');
            $clientId = Request::header('X-Client-Id', 'default');

            if (!$encryptedKey || !$encryptedData) {
                return json(['code' => 400, 'msg' => '缺少加密参数']);
            }

            // 2. 用服务端私钥解密 AES 密钥
            $aesKeyBinary = CryptoService::decryptByPrivateKey($encryptedKey);
            if (!$aesKeyBinary) {
                return json(['code' => 401, 'msg' => '解密AES密钥失败']);
            }
            $aesKey = base64_encode($aesKeyBinary);

            // 3. 用 AES 解密业务数据
            $jsonData = CryptoService::aesDecrypt($encryptedData, $aesKey);
            if (!$jsonData) {
                return json(['code' => 402, 'msg' => '解密业务数据失败']);
            }

            $data = json_decode($jsonData, true);
            if (!$data || !isset($data['class'], $data['method'])) {
                return json(['code' => 403, 'msg' => '请求格式错误']);
            }

            // 4. 执行 RPC 调用
            $class = $data['class'];
            $method = $data['method'];
            $params = $data['params'] ?? [];

            if (!class_exists($class)) {
                return json(['code' => 404, 'msg' => '服务不存在']);
            }

            $service = app($class);
            if (!method_exists($service, $method)) {
                return json(['code' => 405, 'msg' => '方法不存在']);
            }

            $result = call_user_func_array([$service, $method], $params);

            // 5. 生成新的 AES 密钥加密响应
            $responseAesKey = CryptoService::generateAesKey(true);
            $responseData = json_encode($result);
            $encryptedResponse = CryptoService::aesEncrypt($responseData, $responseAesKey);

            // 6. 用客户端公钥加密响应AES密钥
            // 根据client_id获取对应的客户端公钥
            $clientPublicKey = CryptoService::getClientPublicKeyByClientId($clientId);
            $binaryResponseKey = base64_decode($responseAesKey);
            $encryptedResponseKey = CryptoService::encryptByPublicKey($binaryResponseKey, $clientPublicKey);

            return json([
                'encrypted_key' => $encryptedResponseKey,   // 客户端公钥加密的AES密钥
                'encrypted_data' => $encryptedResponse,     // AES加密的业务数据
            ]);

        } catch (\Exception $e) {
            Log::error('RPC调用异常: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '系统错误: ' . $e->getMessage()]);
        }
    }

}