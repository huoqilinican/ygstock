<?php


namespace app\common;

use app\service\CryptoService;
use app\service\RpcService;
use GuzzleHttp\Client;
use think\facade\Config;

class RpcClient
{
    private $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 10.0,
            'verify' => false
        ]);
    }


//    public function call($base_url, $class, $method, $params = [])
//    {
//        try {
//            $client = new Client(['base_uri' => $base_url, 'timeout' => 30]);
//            $response = $client->post('rpc', [
//                'json' => ['class' => $class, 'method' => $method, 'params' => $params]
//            ]);
//            // ====== 调试信息 ======
//            // 1. 状态码
//            //echo "状态码: " . $response->getStatusCode() . "\n";
//
//            // 2. 响应头
//            //echo "响应头: \n";print_r($response->getHeaders());
//
//            // 3. 原始响应体
//            $body = $response->getBody()->getContents();
//            //echo "原始响应体: " . $body . "\n";
//
//            // 4. 解析 JSON
//            $data = json_decode($body, true);
//            //echo "解析后数据: \n";print_r($data);
//
//            // 5. 检查 JSON 解析是否成功
//            if (json_last_error() !== JSON_ERROR_NONE) {
//                echo "JSON 解析错误: " . json_last_error_msg() . "\n";
//            }
//
//            return $data;
//
//        } catch (\GuzzleHttp\Exception\ClientException $e) {
//            // 4xx 错误
//            //echo "客户端错误: " . $e->getMessage() . "\n";
//            return "响应内容: " . $e->getResponse()->getBody()->getContents() . "\n";
//        } catch (\GuzzleHttp\Exception\ServerException $e) {
//            // 5xx 错误
//            //echo "服务端错误: " . $e->getMessage() . "\n";
//            return "服务端响应内容: " . $e->getResponse()->getBody()->getContents() . "\n";
//        } catch (\Exception $e) {
//            return "其他错误: " . $e->getMessage() . "\n";
//        }
//    }

    /**
     * 新版aes+rsa加密方式调用
     * @param $clientId
     * @param $class
     * @param $method
     * @param array $params
     * @return array|mixed|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @author foxme
     * @date 2026/7/1 16:07
     * Description: 新版aes+rsa加密方式调用
     */
    public function call($clientId, $class, $method, $params = [])
    {
        try {
            // 1. 生成随机 AES 密钥（Base64编码的字符串）
            $aesKey = CryptoService::generateAesKey(true);

            // 2. 业务数据用 AES 加密
            $requestData = [
                'class' => $class,
                'method' => $method,
                'params' => $params,
                'timestamp' => time(), // 防重放攻击
            ];

            // 2. 用公钥加密
            $jsonData = json_encode($requestData);
            $encryptedData = CryptoService::aesEncrypt($jsonData, $aesKey);

            // 3. 用服务端公钥加密 AES 密钥（AES密钥是Base64字符串，需要转二进制）
            $binaryAesKey = base64_decode($aesKey);
            $encryptedKey = CryptoService::encryptByServerPublicKey($binaryAesKey);
            if (!$encryptedKey) {
                throw new \Exception('AES密钥加密失败');
            }

            $rpc_url = RpcService::$clientApiUrlData[$clientId];//拼接rpc请求url

            // 4. 发送请求
            $response = $this->httpClient->post($rpc_url, [
                'json' => [
                    'encrypted_key' => $encryptedKey,
                    'encrypted_data' => $encryptedData,
                ],
                'headers' => [
                    'X-RPC-Timestamp' => $requestData['timestamp'],
                    'X-Client-Id' => $clientId,
                ],
//                'on_stats' => function ($stats) {
//                    echo "[请求耗时] " . $stats->getTransferTime() . " 秒\n";
//                }
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            if (!$body || !isset($body['encrypted_key'], $body['encrypted_data'])) {
                throw new \Exception('服务端响应格式错误');
            }

            // 5. 用客户端私钥解密响应AES密钥
            $responseAesKeyBinary = CryptoService::decryptByClientPrivateKey($body['encrypted_key'], $clientId);
            if (!$responseAesKeyBinary) {
                throw new \Exception('响应AES密钥解密失败');
            }
            $responseAesKey = base64_encode($responseAesKeyBinary);

            // 6. 用AES密钥解密响应数据
            $decryptedResponse = CryptoService::aesDecrypt($body['encrypted_data'], $responseAesKey);
            if (!$decryptedResponse) {
                throw new \Exception('响应数据解密失败');
            }
            $responseData = json_decode($decryptedResponse, true);
            if (!$responseData) {
                throw new \Exception('响应数据JSON解析失败');
            }

            return $responseData;

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 4xx 错误
            //echo "客户端错误: " . $e->getMessage() . "\n";
            throw new \Exception("客户端响应内容: " . $e->getResponse()->getBody()->getContents() . "\n");
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            // 5xx 错误
            //echo "服务端错误: " . $e->getMessage() . "\n";
            throw new \Exception("服务端响应内容: " . $e->getResponse()->getBody()->getContents() . "\n");
        } catch (\Exception $e) {
            throw new \Exception("其他错误: " . $e->getMessage() . "\n");
        }
    }

}