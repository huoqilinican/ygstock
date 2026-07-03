<?php


namespace app\common;

use app\service\CryptoService;
use app\service\RpcService;
use GuzzleHttp\Client;
use think\facade\Config;

class RpcClient
{
//    private $httpClient;
//
//    public function __construct()
//    {
//        $this->httpClient = new Client([
//            'timeout' => 10.0,
//            'verify' => false
//        ]);
//    }
//
//
////    public function call($base_url, $class, $method, $params = [])
////    {
////        try {
////            $client = new Client(['base_uri' => $base_url, 'timeout' => 30]);
////            $response = $client->post('rpc', [
////                'json' => ['class' => $class, 'method' => $method, 'params' => $params]
////            ]);
////            // ====== 调试信息 ======
////            // 1. 状态码
////            //echo "状态码: " . $response->getStatusCode() . "\n";
////
////            // 2. 响应头
////            //echo "响应头: \n";print_r($response->getHeaders());
////
////            // 3. 原始响应体
////            $body = $response->getBody()->getContents();
////            //echo "原始响应体: " . $body . "\n";
////
////            // 4. 解析 JSON
////            $data = json_decode($body, true);
////            //echo "解析后数据: \n";print_r($data);
////
////            // 5. 检查 JSON 解析是否成功
////            if (json_last_error() !== JSON_ERROR_NONE) {
////                echo "JSON 解析错误: " . json_last_error_msg() . "\n";
////            }
////
////            return $data;
////
////        } catch (\GuzzleHttp\Exception\ClientException $e) {
////            // 4xx 错误
////            //echo "客户端错误: " . $e->getMessage() . "\n";
////            return "响应内容: " . $e->getResponse()->getBody()->getContents() . "\n";
////        } catch (\GuzzleHttp\Exception\ServerException $e) {
////            // 5xx 错误
////            //echo "服务端错误: " . $e->getMessage() . "\n";
////            return "服务端响应内容: " . $e->getResponse()->getBody()->getContents() . "\n";
////        } catch (\Exception $e) {
////            return "其他错误: " . $e->getMessage() . "\n";
////        }
////    }
//
//    /**
//     * 新版aes+rsa加密方式调用
//     * @param $clientId
//     * @param $class
//     * @param $method
//     * @param array $params
//     * @return array|mixed|string
//     * @throws \GuzzleHttp\Exception\GuzzleException
//     * @author foxme
//     * @date 2026/7/1 16:07
//     * Description: 新版aes+rsa加密方式调用
//     */
//    public function call($clientId, $class, $method, $params = [])
//    {
//        try {
//            // 1. 生成随机 AES 密钥（Base64编码的字符串）
//            $aesKey = CryptoService::generateAesKey(true);
//
//            // 2. 业务数据用 AES 加密
//            $requestData = [
//                'class' => $class,
//                'method' => $method,
//                'params' => $params,
//                'timestamp' => time(), // 防重放攻击
//            ];
//
//            // 2. 用公钥加密
//            $jsonData = json_encode($requestData);
//            $encryptedData = CryptoService::aesEncrypt($jsonData, $aesKey);
//
//            // 3. 用服务端公钥加密 AES 密钥（AES密钥是Base64字符串，需要转二进制）
//            $binaryAesKey = base64_decode($aesKey);
//            $encryptedKey = CryptoService::encryptByServerPublicKey($binaryAesKey);
//            if (!$encryptedKey) {
//                throw new \Exception('AES密钥加密失败');
//            }
//
//            $rpc_url = RpcService::$clientApiUrlData[$clientId];//拼接rpc请求url
//
//            // 4. 发送请求
//            $response = $this->httpClient->post($rpc_url, [
//                'json' => [
//                    'encrypted_key' => $encryptedKey,
//                    'encrypted_data' => $encryptedData,
//                ],
//                'headers' => [
//                    'X-RPC-Timestamp' => $requestData['timestamp'],
//                    'X-Client-Id' => $clientId,
//                ],
////                'on_stats' => function ($stats) {
////                    echo "[请求耗时] " . $stats->getTransferTime() . " 秒\n";
////                }
//            ]);
//
//            $body = json_decode($response->getBody()->getContents(), true);
//            if (!$body || !isset($body['encrypted_key'], $body['encrypted_data'])) {
//                throw new \Exception('服务端响应格式错误');
//            }
//
//
//            // 5. 用客户端私钥解密响应AES密钥
//            $responseAesKeyBinary = CryptoService::decryptByClientPrivateKey($body['encrypted_key'], $clientId);
//            if (!$responseAesKeyBinary) {
//                throw new \Exception('响应AES密钥解密失败');
//            }
//            $responseAesKey = base64_encode($responseAesKeyBinary);
//
//            // 6. 用AES密钥解密响应数据
//            $decryptedResponse = CryptoService::aesDecrypt($body['encrypted_data'], $responseAesKey);
//            if (!$decryptedResponse) {
//                throw new \Exception('响应数据解密失败');
//            }
//            $responseData = json_decode($decryptedResponse, true);
//            if (!$responseData) {
//                throw new \Exception('响应数据JSON解析失败');
//            }
//
//            return $responseData;
//
//        } catch (\GuzzleHttp\Exception\ClientException $e) {
//            // 4xx 错误
//            //echo "客户端错误: " . $e->getMessage() . "\n";
//            throw new \Exception("客户端响应内容: " . $e->getResponse()->getBody()->getContents() . "\n");
//        } catch (\GuzzleHttp\Exception\ServerException $e) {
//            // 5xx 错误
//            //echo "服务端错误: " . $e->getMessage() . "\n";
//            throw new \Exception("服务端响应内容: " . $e->getResponse()->getBody()->getContents() . "\n");
//        } catch (\Exception $e) {
//            throw new \Exception("其他错误: " . $e->getMessage() . "\n");
//        }
//    }


    private $httpClient;
    private $serverUrl;
    private $clientId;
    private $clientPrivateKey;  // 客户端私钥用于签名

    public function __construct($clientId = 'client_stock')
    {
        $this->serverUrl = RpcService::$clientApiUrlData[$clientId];//拼接rpc请求url
        $this->clientId = $clientId;

        // 加载客户端私钥（用于签名）
        $this->clientPrivateKey = CryptoService::getClientPrivateKeyByClientId($clientId);//$this->loadPrivateKey();

        $this->httpClient = new Client([
            'timeout' => 10.0,
            'verify' => false, // 生产环境应开启SSL验证
        ]);
    }

    /**
     * 加载客户端私钥（用于签名）
     */
    private function loadPrivateKey()
    {
        $path = Config::get('rpc.client_private_key_path');
        if (!file_exists($path)) {
            throw new \Exception("客户端私钥文件不存在: {$path}");
        }

        $content = file_get_contents($path);
        $privateKey = openssl_pkey_get_private($content);
        if ($privateKey === false) {
            throw new \Exception('客户端私钥无效: ' . openssl_error_string());
        }

        return $privateKey;
    }

    /**
     * 生成签名
     */
    private function generateSignature($data, $timestamp)
    {
        // 签名数据：业务数据 + 时间戳
        $signData = $data . '|' . $timestamp . '|' . $this->clientId;

        $signature = '';
        openssl_sign($signData, $signature, $this->clientPrivateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * 验证服务端响应签名
     */
    private function verifyResponseSignature($data, $signature, $serverPublicKey)
    {
        $signData = $data . '|' . $this->clientId;
        $decodedSignature = base64_decode($signature);

        $result = openssl_verify($signData, $decodedSignature, $serverPublicKey, OPENSSL_ALGO_SHA256);

        if ($result === 1) {
            return true;
        } elseif ($result === 0) {
            throw new \Exception('签名验证失败：数据被篡改');
        } else {
            throw new \Exception('签名验证异常: ' . openssl_error_string());
        }
    }

    /**
     * @param $clientId
     * @param $class
     * @param $method
     * @param array $params
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @author foxme
     * @date 2026/7/2 21:36
     * Description: rpc客户端入口
     */
    public function call($clientId,$class, $method, $params = [])
    {
        try {
            // 1. 生成AES密钥和加密数据
            $aesKey = CryptoService::generateAesKey(true);
            $requestData = [
                'class' => $class,
                'method' => $method,
                'params' => $params,
                'timestamp' => time(),
                'client_id' => $clientId,
                'nonce' => uniqid('', true), // 防重放攻击
            ];

            $jsonData = json_encode($requestData);
            $encryptedData = CryptoService::aesEncrypt($jsonData, $aesKey);

            // 2. 生成请求签名（防篡改）
            $signature = $this->generateSignature($jsonData, $requestData['timestamp']);

            // 3. 加密AES密钥
            $binaryAesKey = base64_decode($aesKey);
            $encryptedKey = CryptoService::encryptByServerPublicKey($binaryAesKey);

            if (!$encryptedKey) {
                throw new \Exception('AES密钥加密失败');
            }

            // 4. 发送请求
            //$startTime = microtime(true);
            //echo "\n[请求时间] " . date('Y-m-d H:i:s') . "\n";
            //echo "[请求URL] {$this->serverUrl}\n";
            //echo "[客户端ID] {$this->clientId}\n";

            $response = $this->httpClient->post($this->serverUrl, [
                'json' => [
                    'encrypted_key' => $encryptedKey,
                    'encrypted_data' => $encryptedData,
                    'signature' => $signature,          // 添加签名
                    'client_id' => $clientId,
                    'timestamp' => $requestData['timestamp'],
                    'nonce' => $requestData['nonce'],
                ],
                'headers' => [
                    'X-RPC-Timestamp' => $requestData['timestamp'],
                    'X-Client-Id' => $clientId,
                    'X-Nonce' => $requestData['nonce'],
                ],
//                'on_stats' => function ($stats) {
//                    echo "[请求耗时] " . $stats->getTransferTime() . " 秒\n";
//                }
            ]);
            //$endTime = microtime(true);
            //echo "[总耗时] " . ($endTime - $startTime) . " 秒\n";

            $statusCode = $response->getStatusCode();
            //echo "[状态码] {$statusCode}\n";

            if ($statusCode !== 200) {
                throw new \Exception("HTTP错误: {$statusCode}");
            }

            $body = $response->getBody()->getContents();
            //echo "[响应长度] " . strlen($body) . " 字节\n";
            //echo "[响应前20000字符] " . substr($body, 0, 20000) . "\n";

//            // 5. 处理响应
//            $statusCode = $response->getStatusCode();
//            if ($statusCode !== 200) {
//                throw new \Exception("HTTP错误: {$statusCode}");
//            }
//

            $result = json_decode($body, true);

            if (!$result) {
                throw new \Exception('响应JSON解析失败');
            }

            // 6. 验证响应签名（防止中间人篡改响应）
            if (!isset($result['signature']) || !isset($result['data'])) {
                throw new \Exception('响应缺少签名或数据');
            }

            // 获取服务端公钥验证响应(两种方案都可以)
            //$serverPublicKey = CryptoService::getClientPublicKeyByClientId($clientId);
            $serverPublicKey = $this->getServerPublicKey();
            $this->verifyResponseSignature(
                $result['data'],
                $result['signature'],
                $serverPublicKey
            );

            // 7. 解密响应数据
            $responseData = json_decode($result['data'], true);
            if (!isset($responseData['encrypted_key'], $responseData['encrypted_data'])) {
                throw new \Exception('响应数据格式错误');
            }

            // 8. 用客户端私钥解密响应AES密钥
            $responseAesKeyBinary = CryptoService::decryptByClientPrivateKey(
                $responseData['encrypted_key'],$clientId
            );

            if (!$responseAesKeyBinary) {
                throw new \Exception('响应AES密钥解密失败');
            }

            $responseAesKey = base64_encode($responseAesKeyBinary);

            // 9. 解密业务数据
            $decryptedResponse = CryptoService::aesDecrypt(
                $responseData['encrypted_data'],
                $responseAesKey
            );

            $finalResult = json_decode($decryptedResponse, true);

//            echo "\n✅ 请求成功\n";
//            echo "响应数据: " . json_encode($finalResult, JSON_UNESCAPED_UNICODE) . "\n";

            return $finalResult;

        } catch (RequestException $e) {
//            echo "\n❌ 请求异常:\n";
//            echo "  错误信息: " . $e->getMessage() . "\n";

//            if ($e->hasResponse()) {
//                echo "  响应状态码: " . $e->getResponse()->getStatusCode() . "\n";
//                echo "  响应内容: " . $e->getResponse()->getBody()->getContents() . "\n";
//            }

            throw $e;

        } catch (\Exception $e) {
            //echo "\n❌ 其他异常: " . $e->getMessage() . "\n";
            throw $e;
        }
    }


    /**
     * @return false|resource
     * @author foxme
     * @date 2026/7/2 21:21
     * Description: 获取服务器公钥
     */
    private function getServerPublicKey()
    {
        // 从配置或缓存获取服务端公钥
        $path = Config::get('rpc.server_public_key_path');
        $content = file_get_contents($path);
        return openssl_pkey_get_public($content);
    }
}