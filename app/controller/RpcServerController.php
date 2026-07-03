<?php

namespace app\controller;

use app\BaseController;
use app\service\CryptoService;
use think\facade\Config;
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


//    /**
//     * @return \think\response\Json
//     * @author foxme
//     * @date 2026/7/2 21:25
//     * Description: rsa+aes方案
//     */
//    public function handle()
//    {
//        try {
//            // 1. 接收加密数据
//            $encryptedKey = Request::post('encrypted_key');
//            $encryptedData = Request::post('encrypted_data');
//            $clientId = Request::header('X-Client-Id', 'default');
//
//            if (!$encryptedKey || !$encryptedData) {
//                return json(['code' => 400, 'msg' => '缺少加密参数']);
//            }
//
//            // 2. 用服务端私钥解密 AES 密钥
//            $aesKeyBinary = CryptoService::decryptByPrivateKey($encryptedKey);
//            if (!$aesKeyBinary) {
//                return json(['code' => 401, 'msg' => '解密AES密钥失败']);
//            }
//            $aesKey = base64_encode($aesKeyBinary);
//
//            // 3. 用 AES 解密业务数据
//            $jsonData = CryptoService::aesDecrypt($encryptedData, $aesKey);
//            if (!$jsonData) {
//                return json(['code' => 402, 'msg' => '解密业务数据失败']);
//            }
//
//            $data = json_decode($jsonData, true);
//            if (!$data || !isset($data['class'], $data['method'])) {
//                return json(['code' => 403, 'msg' => '请求格式错误']);
//            }
//
//            // 4. 执行 RPC 调用
//            $class = $data['class'];
//            $method = $data['method'];
//            $params = $data['params'] ?? [];
//
//            if (!class_exists($class)) {
//                return json(['code' => 404, 'msg' => '服务不存在']);
//            }
//
//            $service = app($class);
//            if (!method_exists($service, $method)) {
//                return json(['code' => 405, 'msg' => '方法不存在']);
//            }
//
//            $result = call_user_func_array([$service, $method], $params);
//
//            // 5. 生成新的 AES 密钥加密响应
//            $responseAesKey = CryptoService::generateAesKey(true);
//            $responseData = json_encode($result);
//            $encryptedResponse = CryptoService::aesEncrypt($responseData, $responseAesKey);
//
//            // 6. 用客户端公钥加密响应AES密钥
//            // 根据client_id获取对应的客户端公钥
//            $clientPublicKey = CryptoService::getClientPublicKeyByClientId($clientId);
//            $binaryResponseKey = base64_decode($responseAesKey);
//            $encryptedResponseKey = CryptoService::encryptByPublicKey($binaryResponseKey, $clientPublicKey);
//
//            return json([
//                'encrypted_key' => $encryptedResponseKey,   // 客户端公钥加密的AES密钥
//                'encrypted_data' => $encryptedResponse,     // AES加密的业务数据
//            ]);
//
//        } catch (\Exception $e) {
//            Log::error('RPC调用异常: ' . $e->getMessage());
//            return json(['code' => 500, 'msg' => '系统错误: ' . $e->getMessage()]);
//        }
//    }


    private $clientPublicKeys = []; // 客户端公钥缓存

    /**
     * @return \think\response\Json
     * @author foxme
     * @date 2026/7/2 21:25
     * Description: 最完美的方案，防止中间人攻击的方案
     */
    public function handle()
    {
        try {
            $input = Request::post();

            // 1. 基础验证
            $required = ['encrypted_key', 'encrypted_data', 'signature', 'client_id', 'timestamp', 'nonce'];
            foreach ($required as $key) {
                if (!isset($input[$key])) {
                    return json(['code' => 400, 'msg' => "缺少参数: {$key}"]);
                }
            }

            // 2. 防重放攻击验证
            $this->validateReplay($input['client_id'], $input['nonce'], $input['timestamp']);

            // 3. 获取客户端公钥
            $clientPublicKey = CryptoService::getClientPublicKeyByClientId($input['client_id']);
            if (!$clientPublicKey) {
                return json(['code' => 403, 'msg' => '未知客户端']);
            }

            // 4. 验证请求签名（防篡改）
            $encryptedKey = $input['encrypted_key'];
            $encryptedData = $input['encrypted_data'];
            $signature = $input['signature'];

            // 解密数据用于验证签名
            $aesKeyBinary = CryptoService::decryptByPrivateKey($encryptedKey);
            if (!$aesKeyBinary) {
                return json(['code' => 401, 'msg' => 'AES密钥解密失败']);
            }
            $aesKey = base64_encode($aesKeyBinary);
            $jsonData = CryptoService::aesDecrypt($encryptedData, $aesKey);

            if (!$jsonData) {
                return json(['code' => 402, 'msg' => '数据解密失败']);
            }

            // 验证签名
            $signData = $jsonData . '|' . $input['timestamp'] . '|' . $input['client_id'];
            $decodedSignature = base64_decode($signature);
            $verifyResult = openssl_verify(
                $signData,
                $decodedSignature,
                $clientPublicKey,
                OPENSSL_ALGO_SHA256
            );

            if ($verifyResult !== 1) {
                Log::warning("签名验证失败", ['client_id' => $input['client_id']]);
                return json(['code' => 403, 'msg' => '签名验证失败']);
            }

            // 5. 执行业务逻辑
            $requestData = json_decode($jsonData, true);
            $class = $requestData['class'];
            $method = $requestData['method'];
            $params = $requestData['params'] ?? [];

            if (!class_exists($class)) {
                return json(['code' => 404, 'msg' => '服务不存在']);
            }

            $service = app($class);
            if (!method_exists($service, $method)) {
                return json(['code' => 405, 'msg' => '方法不存在']);
            }

            $result = call_user_func_array([$service, $method], $params);

            // 6. 生成响应
            $responseAesKey = CryptoService::generateAesKey(true);
            $responseData = json_encode(['code' => 0, 'data' => $result]);
            $encryptedResponse = CryptoService::aesEncrypt($responseData, $responseAesKey);

            $binaryResponseKey = base64_decode($responseAesKey);
            $encryptedResponseKey = CryptoService::encryptByPublicKey($binaryResponseKey, $clientPublicKey);

            // 7. 生成响应签名
            $responseJson = json_encode([
                'encrypted_key' => $encryptedResponseKey,
                'encrypted_data' => $encryptedResponse,
            ]);
            $responseSignData = $responseJson . '|' . $input['client_id'];

            $responseSignature = '';
            //openssl_sign($responseSignData, $responseSignature, CryptoService::getClientPrivateKeyByClientId($input['client_id']), OPENSSL_ALGO_SHA256);
            openssl_sign($responseSignData, $responseSignature, CryptoService::getServerPrivateKey(), OPENSSL_ALGO_SHA256);

            // 8. 返回响应
            return json([
                'signature' => base64_encode($responseSignature),
                'data' => $responseJson,
            ]);

        } catch (\Exception $e) {
            Log::error('RPC异常: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    private function validateReplay($clientId, $nonce, $timestamp)
    {
        // 检查时间戳
        $timeDiff = time() - $timestamp;
        if (abs($timeDiff) > 300) {
            throw new \Exception('请求已过期');
        }

        // 检查Nonce（使用Redis或缓存）
        $cacheKey = "rpc_nonce_{$clientId}_{$nonce}";
        if (cache($cacheKey)) {
            throw new \Exception('重复请求（重放攻击）');
        }

        cache($cacheKey, true, 300);
    }

    private function getClientPublicKey($clientId)
    {
        // 从配置或数据库获取
        $keys = Config::get('rpc.client_public_keys', []);
        if (!isset($keys[$clientId])) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($keys[$clientId]);
        if ($publicKey === false) {
            Log::error("客户端公钥无效: {$clientId}");
            return false;
        }

        return $publicKey;
    }
}