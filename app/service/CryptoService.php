<?php


namespace app\service;

use think\Exception;
use think\facade\Config;
use think\facade\Log;

class CryptoService
{

    private static $privateKey;
    private static $publicKey;

    private static $serverPublicKey = null;   // 服务端公钥
    private static $serverPrivateKey = null;   // 服务端私钥
    private static $clientPrivateKey = null;  // 客户端私钥
    private static $clientPublicKey = null;   // 客户端公钥（用于发给服务端）

    /**
     * 获取服务端公钥（用于加密请求）
     */
    public static function getServerPublicKey()
    {
        if (self::$serverPublicKey === null) {
            try {
                $path = Config::get('rpc.server_public_key_path');
                if (!file_exists($path)) {
                    throw new \Exception("服务端公钥文件不存在: {$path}");
                }

                $content = file_get_contents($path);
                $content = trim($content);

                if (empty($content)) {
                    throw new \Exception("服务端公钥文件内容为空");
                }

                self::$serverPublicKey = openssl_pkey_get_public($content);
                if (self::$serverPublicKey === false) {
                    throw new \Exception('服务端公钥无效: ' . openssl_error_string());
                }

                Log::info("服务端公钥加载成功");

            } catch (\Exception $e) {
                Log::error("加载服务端公钥失败: " . $e->getMessage());
                throw $e;
            }
        }
        return self::$serverPublicKey;
    }

    /**
     * @return false|resource|null
     * @throws \Exception
     * @author foxme
     * @date 2026/7/2 20:18
     * Description: 获取服务端私钥
     */
    public static function getServerPrivateKey()
    {
        if (self::$serverPrivateKey === null) {
            try {
                $path = Config::get('rpc.server_private_key_path');
                if (!file_exists($path)) {
                    throw new \Exception("服务端私钥文件不存在: {$path}");
                }

                $content = file_get_contents($path);
                $content = trim($content);

                if (empty($content)) {
                    throw new \Exception("服务端私钥文件内容为空");
                }

                self::$serverPrivateKey = openssl_pkey_get_private($content);
                if (self::$serverPrivateKey === false) {
                    throw new \Exception('服务端私钥无效: ' . openssl_error_string());
                }

                Log::info("服务端私钥加载成功");

            } catch (\Exception $e) {
                Log::error("加载服务端私钥失败: " . $e->getMessage());
                throw $e;
            }
        }
        return self::$serverPrivateKey;
    }

    /**
     * 获取客户端私钥（用于解密响应）
     */
    public static function getClientPrivateKey()
    {
        if (self::$clientPrivateKey === null) {
            try {
                $path = Config::get('rpc.client_private_key_path');
                if (!file_exists($path)) {
                    throw new \Exception("客户端私钥文件不存在: {$path}");
                }

                $content = file_get_contents($path);
                $content = trim($content);

                if (empty($content)) {
                    throw new \Exception("客户端私钥文件内容为空");
                }

                // 检查私钥格式
                if (strpos($content, 'BEGIN PRIVATE KEY') === false &&
                    strpos($content, 'BEGIN RSA PRIVATE KEY') === false) {
                    throw new \Exception("客户端私钥格式不正确");
                }

                self::$clientPrivateKey = openssl_pkey_get_private($content);
                if (self::$clientPrivateKey === false) {
                    throw new \Exception('客户端私钥无效: ' . openssl_error_string());
                }

                Log::info("客户端私钥加载成功");

            } catch (\Exception $e) {
                Log::error("加载客户端私钥失败: " . $e->getMessage());
                throw $e;
            }
        }
        return self::$clientPrivateKey;
    }

    /**
     * 获取客户端公钥（用于提供给服务端）
     */
    public static function getClientPublicKey()
    {
        if (self::$clientPublicKey === null) {
            try {
                $path = Config::get('rpc.client_public_key_path');
                if (!file_exists($path)) {
                    throw new \Exception("客户端公钥文件不存在: {$path}");
                }

                $content = file_get_contents($path);
                $content = trim($content);

                if (empty($content)) {
                    throw new \Exception("客户端公钥文件内容为空");
                }

                self::$clientPublicKey = openssl_pkey_get_public($content);
                if (self::$clientPublicKey === false) {
                    throw new \Exception('客户端公钥无效: ' . openssl_error_string());
                }

                Log::info("客户端公钥加载成功");

            } catch (\Exception $e) {
                Log::error("加载客户端公钥失败: " . $e->getMessage());
                throw $e;
            }
        }
        return self::$clientPublicKey;
    }

    /**
     * 使用服务端公钥加密（客户端加密请求）
     * @param string $data 原始数据（二进制）
     * @return string|false Base64编码的加密数据
     */
    public static function encryptByServerPublicKey($data)
    {
        try {
            $key = self::getServerPublicKey();
            $result = '';

            // 分段加密（RSA 2048位最多加密245字节）
            $chunks = str_split($data, 117);
            foreach ($chunks as $chunk) {
                $encrypted = '';
                if (!openssl_public_encrypt($chunk, $encrypted, $key)) {
                    $error = openssl_error_string();
                    Log::error("服务端公钥加密失败: {$error}");
                    return false;
                }
                $result .= $encrypted;
            }

            return base64_encode($result);

        } catch (\Exception $e) {
            Log::error("加密异常: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 使用客户端私钥解密（客户端解密响应）
     * @param string $encryptedData Base64编码的加密数据
     * @param string $clientId 客户端ID
     * @return string|false 解密后的原始二进制数据
     */
    public static function decryptByClientPrivateKey($encryptedData,$clientId)
    {
        try {
            //$key = self::getClientPrivateKey();//原始逻辑
            $key = self::getClientPrivateKeyByClientId($clientId);//通过clientId获取客户端私钥
            // Base64解码
            $data = base64_decode($encryptedData);
            if ($data === false) {
                Log::error("Base64解码失败");
                return false;
            }

            $result = '';
            // 分段解密（2048位密钥最多解密256字节）
            $chunks = str_split($data, 256);
            foreach ($chunks as $chunk) {
                $decrypted = '';
                if (!openssl_private_decrypt($chunk, $decrypted, $key)) {
                    $error = openssl_error_string();
                    Log::error("客户端私钥解密失败: {$error}");
                    return false;
                }
                $result .= $decrypted;
            }

            return $result;

        } catch (\Exception $e) {
            Log::error("解密异常: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取客户端公钥的PEM字符串（用于发送给服务端）
     */
    public static function getClientPublicKeyString()
    {
        $key = self::getClientPublicKey();
        openssl_pkey_export($key, $output);
        return $output;
    }

    /**
     * 生成 AES-256-CBC 密钥
     * @param bool $encoded 是否返回Base64编码
     * @return string
     */
    public static function generateAesKey($encoded = true)
    {
        $key = openssl_random_pseudo_bytes(32);
        if ($encoded) {
            return base64_encode($key);
        }
        return $key;
    }

    /**
     * AES 加密
     * @param string $data 原始数据
     * @param string $base64Key Base64编码的AES密钥
     * @return string Base64编码的加密数据
     */
    public static function aesEncrypt($data, $base64Key)
    {
        try {
            // Base64解码密钥
            $key = base64_decode($base64Key);
            if (strlen($key) !== 32) {
                throw new \Exception("AES密钥长度错误: " . strlen($key) . "，期望32");
            }

            $ivLength = openssl_cipher_iv_length('aes-256-cbc');
            $iv = openssl_random_pseudo_bytes($ivLength);

            $encrypted = openssl_encrypt(
                $data,
                'aes-256-cbc',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                throw new \Exception('AES加密失败: ' . openssl_error_string());
            }

            // 返回 IV + 加密数据 的Base64编码
            return base64_encode($iv . $encrypted);

        } catch (\Exception $e) {
            Log::error("AES加密异常: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * AES 解密
     * @param string $base64Data Base64编码的加密数据
     * @param string $base64Key Base64编码的AES密钥
     * @return string 解密后的原始数据
     */
    public static function aesDecrypt($base64Data, $base64Key)
    {
        try {
            // Base64解码密钥
            $key = base64_decode($base64Key);
            if (strlen($key) !== 32) {
                throw new \Exception("AES密钥长度错误: " . strlen($key) . "，期望32");
            }

            // Base64解码数据
            $data = base64_decode($base64Data);
            if ($data === false) {
                throw new \Exception("Base64解码失败");
            }

            $ivLength = openssl_cipher_iv_length('aes-256-cbc');
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);

            $decrypted = openssl_decrypt(
                $encrypted,
                'aes-256-cbc',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new \Exception('AES解密失败: ' . openssl_error_string());
            }

            return $decrypted;

        } catch (\Exception $e) {
            Log::error("AES解密异常: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取客户端公钥（根据客户端ID）
     */
    public static function getClientPublicKeyByClientId($clientId)
    {
        // 可以从配置或数据库中获取
        $clientKeys = Config::get('rpc.client_public_keys', []);

        if (!isset($clientKeys[$clientId])) {
            throw new \Exception("未找到客户端 {$clientId} 的公钥");
        }

        $content = $clientKeys[$clientId];
        $publicKey = openssl_pkey_get_public($content);

        if ($publicKey === false) {
            throw new \Exception("客户端公钥无效: " . openssl_error_string());
        }

        return $publicKey;
    }


    /**
     * @param $clientId
     * @return false|resource
     * @throws \Exception
     * @author foxme
     * @date 2026/7/2 14:38
     * Description: 根据clientId获取客户端私钥
     */
    public static function getClientPrivateKeyByClientId($clientId)
    {
        try {
            // 可以从配置或数据库中获取
            $clientKeys = Config::get('rpc.client_private_keys', []);

            if (!isset($clientKeys[$clientId])) {
                throw new \Exception("未找到clientId {$clientId} 的私钥");
            }

            $content = $clientKeys[$clientId];
            $content = trim($content);

            if (empty($content)) {
                throw new \Exception("clientId {$clientId} 的私钥文件内容为空");
            }

            // 检查私钥格式
            if (strpos($content, 'BEGIN PRIVATE KEY') === false &&
                strpos($content, 'BEGIN RSA PRIVATE KEY') === false) {
                throw new \Exception("clientId {$clientId} 的私钥格式不正确");
            }

            $privateKey = openssl_pkey_get_private($content);
            if ($privateKey === false) {
                throw new \Exception("clientId {$clientId} 的私钥无效: " . openssl_error_string());
            }

            Log::info("clientId {$clientId} 的私钥加载成功");
            return $privateKey;//返回clientId的私钥

        } catch (\Exception $e) {
            Log::error("加载clientId {$clientId} 的私钥失败: " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * 使用指定公钥加密
     */
    public static function encryptByPublicKey($data, $publicKey)
    {
        $result = '';
        $chunks = str_split($data, 117);
        foreach ($chunks as $chunk) {
            $encrypted = '';
            if (!openssl_public_encrypt($chunk, $encrypted, $publicKey)) {
                throw new \Exception('公钥加密失败: ' . openssl_error_string());
            }
            $result .= $encrypted;
        }
        return base64_encode($result);
    }

















    /**
     * 获取私钥（服务端用）
     */
    public static function getPrivateKey()
    {
        if (!self::$privateKey) {
            $path = Config::get('rpc.server_private_key_path');
            self::$privateKey = openssl_pkey_get_private(file_get_contents($path));
        }
        return self::$privateKey;
    }

    /**
     * 获取公钥（服务端也可用于验签）
     */
    public static function getPublicKey()
    {
        if (!self::$publicKey) {
            $path = Config::get('rpc.public_key_path');
            self::$publicKey = openssl_pkey_get_public(file_get_contents($path));
        }
        return self::$publicKey;
    }

    /**
     * 新版客户端公钥加密（客户端用，这里保留供服务端验签）
     * @param $data
     * @return bool|string
     * @author foxme
     * @date 2026/7/1 14:37
     * Description: 客户端公钥加密（客户端用，这里保留供服务端验签）
     */
    public static function encryptByPublicKeys($data)
    {
        $key = self::getPublicKey();
        if (!$key) {
            throw new Exception('公钥无效: ' . openssl_error_string());
        }
        $result = '';
        $chunks = str_split($data, 117);
        foreach ($chunks as $chunk) {
            $encrypted = '';
            if (!openssl_public_encrypt($chunk, $encrypted, $key)) {
                return false;
            }
            $result .= $encrypted;
        }
        return base64_encode($result);
    }


    /**
     * 新版服务端私钥解密（服务端解密客户端发送的数据）
     * @param $encryptedData
     * @return bool|string
     * @author foxme
     * @date 2026/7/1 14:38
     * Description: 服务端私钥解密（服务端解密客户端发送的数据）
     */
    public static function decryptByPrivateKey($encryptedData)
    {
        $key = self::getPrivateKey();
        $data = base64_decode($encryptedData);
        $result = '';
        // RSA 分段解密（2048位密钥最多解密245字节）
        $chunks = str_split($data, 256);
        foreach ($chunks as $chunk) {
            $decrypted = '';
            if (!openssl_private_decrypt($chunk, $decrypted, $key)) {
                return false;
            }
            $result .= $decrypted;
        }
        return $result;
    }


    /**
     * 新版服务端私钥加密（服务端加密响应数据）
     * @param $data
     * @return bool|string
     * @author foxme
     * @date 2026/7/1 14:38
     * Description: 服务端私钥加密（服务端加密响应数据）
     */
    public static function encryptByPrivateKey($data)
    {
        $key = self::getPrivateKey();
        $result = '';
        // 分段加密（最多117字节）
        $chunks = str_split($data, 117);
        foreach ($chunks as $chunk) {
            $encrypted = '';
            if (!openssl_private_encrypt($chunk, $encrypted, $key)) {
                return false;
            }
            $result .= $encrypted;
        }
        return base64_encode($result);
    }


    /**
     * 新版公钥解密
     * @param $encryptedData
     * @throws \Exception
     * @author foxme
     * @date 2026/7/1 15:24
     * Description: 新版公钥解密（客户端解密服务端响应，需要私钥才能解，所以这里仅做示例）
     * 实际上客户端解密响应需要用私钥，但为了安全，客户端也可持有私钥
     * 或者采用 RSA + AES 混合加密方案（推荐）
     */
    public static function decryptByPublicKey($encryptedData)
    {
        // 注意：公钥不能解密公钥加密的数据，这里仅作占位
        // 实际应使用私钥解密，但客户端如果只持有公钥，则只能验签
        //throw new \Exception('公钥无法解密数据，请使用RSA+AES混合方案');
        $key = self::getPublicKey();
        $data = base64_decode($encryptedData);
        $result = '';
        // RSA 分段解密（2048位密钥最多解密245字节）
        $chunks = str_split($data, 256);
        foreach ($chunks as $chunk) {
            $decrypted = '';
            if (!openssl_private_decrypt($chunk, $decrypted, $key)) {
                return false;
            }
            $result .= $decrypted;
        }
        return $result;
    }


    /**
     * 新版AES 加密（CBC 模式）
     * @param $data
     * @param $key
     * @return string
     * @author foxme
     * @date 2026/7/1 15:41
     * Description: 新版AES 加密（CBC 模式）
     */
    public static function aesEncrypts($data, $key)
    {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * 新版AES 解密
     * @param $encryptedData
     * @param $key
     * @return false|string
     * @author foxme
     * @date 2026/7/1 15:41
     * Description: 新版AES 解密
     */
    public static function aesDecrypts($encryptedData, $key)
    {
        $data = base64_decode($encryptedData);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * 新版aes生成随机 AES 密钥（256位）
     * @return false|string
     * @author foxme
     * @date 2026/7/1 15:40
     * Description: 新版aes生成随机 AES 密钥（256位）
     */
    public static function generateAesKeys()
    {
        return openssl_random_pseudo_bytes(32);
    }


    /**
     * 客户端加密：使用服务端公钥加密明文数据
     * @param string $plaintext 待加密的原始数据（字符串）
     * @return string|false         返回 Base64 编码的密文包（包含加密后的AES密钥、IV和密文），失败返回false
     */
    public static function encrypt($plaintext)
    {
        // 1. 加载公钥
        $publicKey = openssl_pkey_get_public(file_get_contents(env('CY_PK_KEY')));
        if (!$publicKey) {
            throw new Exception('公钥无效');
        }

        // 2. 生成随机 AES 密钥和 IV（256位密钥，128位IV）
        $aesKey = openssl_random_pseudo_bytes(32);   // AES-256
        $iv = openssl_random_pseudo_bytes(16);   // CBC IV长度16字节

        // 3. 使用 AES-256-CBC 加密业务数据
        $ciphertext = openssl_encrypt(
            $plaintext,
            'AES-256-CBC',
            $aesKey,
            OPENSSL_RAW_DATA,   // 输出原始二进制
            $iv
        );
        if ($ciphertext === false) {
            throw new Exception('AES加密失败');
        }

        // 4. 使用 RSA 公钥加密 AES 密钥（使用 OAEP 填充，更安全）
        $encryptedAesKey = '';
        if (!openssl_public_encrypt($aesKey, $encryptedAesKey, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new Exception('RSA公钥加密AES密钥失败');
        }

        // 5. 组装成数组并 Base64 编码返回
        $payload = [
            'key' => base64_encode($encryptedAesKey), // RSA加密后的AES密钥
            'iv' => base64_encode($iv),              // IV
            'data' => base64_encode($ciphertext)       // AES密文
        ];
        return base64_encode(json_encode($payload));
    }

    /**
     * 服务端解密：使用私钥解密客户端发来的密文包
     * @param string
     * @return string|false           返回解密后的原始明文，失败返回false
     */
    public static function decrypt($encryptedPackage)
    {
        // 1. 解码 JSON 包
        $json = base64_decode($encryptedPackage);
        if ($json === false) {
            throw new Exception('Base64解码失败');
        }
        $payload = json_decode($json, true);
        if (!isset($payload['key'], $payload['iv'], $payload['data'])) {
            throw new Exception('无效的密文包格式');
        }

        // 2. 加载私钥
        $privateKey = openssl_pkey_get_private(file_get_contents(env('CY_PT_KEY')));
        if (!$privateKey) {
            throw new Exception('私钥无效');
        }

        // 3. 解码各字段
        $encryptedAesKey = base64_decode($payload['key']);
        $iv = base64_decode($payload['iv']);
        $ciphertext = base64_decode($payload['data']);

        // 4. 使用 RSA 私钥解密 AES 密钥
        $aesKey = '';
        if (!openssl_private_decrypt($encryptedAesKey, $aesKey, $privateKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new Exception('RSA私钥解密AES密钥失败');
        }

        // 5. 使用 AES 密钥解密业务数据
        $plaintext = openssl_decrypt(
            $ciphertext,
            'AES-256-CBC',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv
        );
        if ($plaintext === false) {
            throw new Exception('AES解密失败');
        }
        return $plaintext;
    }

    /**
     * 客户端加密（发送API请求）
     * @param $data
     * @author foxme
     * @date 2026/6/29 9:26
     * Description: 客户端加密（发送API请求）
     */
    public static function clientEncrypt($data)
    {
        $data = ['user_id' => 123, 'action' => 'update', 'timestamp' => time()];
        $plaintext = is_array($data) ? json_encode($data) : $data;

        try {
            $encryptedPackage = self::encrypt($plaintext, env('CY_PK_KEY'));
            // 将 $encryptedPackage 作为 POST 字段发送给 API 接口
            // 例如使用 cURL：
            $ch = curl_init(env('REQUEST_API_URL'));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['payload' => $encryptedPackage]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            // 处理响应...
        } catch (Exception $e) {
            return '加密失败：' . $e->getMessage();
        }
    }


    /**
     * 服务端接收并解密（API入口）
     * @param $data
     * @author foxme
     * @date 2026/6/29 9:27
     * Description: 服务端接收并解密（API入口）
     */
    public static function serverDecrypt($data)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payload'])) {
            try {
                $encryptedPackage = $_POST['payload'];
                $plaintext = self::decrypt($encryptedPackage, env('CY_PK_KEY'));
                $requestData = json_decode($plaintext, true);

                // 现在可以使用 $requestData 执行业务逻辑
                // 例如：校验时间戳、处理更新等

                // 返回成功响应（也可以加密返回，按需）
                return json(['status' => 'success', 'data' => $requestData]);
            } catch (Exception $e) {
                http_response_code(400);
                return json(['error' => $e->getMessage()]);
            }
        } else {
            http_response_code(405);
            return json(['error' => 'Method not allowed or missing payload']);
        }
    }


    /**
     * 另外一种简化加解密方案：使用 openssl_seal 和 openssl_open
     * @author foxme
     * @date 2026/6/29 9:30
     * Description: 另外一种简化加解密方案：使用 openssl_seal 和 openssl_open
     */
    public static function simpleEncryptDecrypt()
    {
        // 客户端加密（使用公钥）
        $publicKey = openssl_pkey_get_public(file_get_contents('public_key.pem'));
        $data = 'hello world';
        openssl_seal($data, $sealedData, $encryptedKeys, [$publicKey]);
        // 将 $sealedData 和 $encryptedKeys[0] base64编码后发送

        // 服务端解密（使用私钥）
        $privateKey = openssl_pkey_get_private(file_get_contents('private_key.pem'));
        openssl_open($sealedData, $decryptedData, $encryptedKeys[0], $privateKey);
    }


    /**
     * 公共post请求
     * @param $path
     * @param $data
     * @param array $headers
     * @return array|mixed
     * @author foxme
     * @date 2026/7/1 9:34
     * Description: 公共post请求
     */
    public static function post($path, $data, $headers = [])
    {
        $url = env('API_URL') . $path;
        $curl = curl_init($url);
        // 设置cURL选项
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data = json_encode($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, self::formatHeaders($headers));
        //echo 'POST请求地址：' . $url . PHP_EOL;
        //echo '请求header：' . json_encode($headers) . PHP_EOL;
        //echo '请求参数：' . $data . PHP_EOL;
        // 执行cURL会话
        $response = curl_exec($curl);
        //echo 'response：' . $response . PHP_EOL;
        curl_close($curl);
        if (!empty($response)) $res = json_decode($response, true);
        return empty($res) ? [] : $res;
    }


    /**
     * 公共get请求
     * @param $path
     * @param array $headers
     * @param array $params
     * @return array|mixed
     * @author foxme
     * @date 2026/7/1 9:33
     * Description: 公共get请求
     */
    public static function get($path, $headers = [], $params = [])
    {
        // 拼接参数到URL
        if (!empty($params)) {
            $path .= '?' . http_build_query($params);
        }
        // 初始化cURL会话
        $curl = curl_init(env('API_URL') . $path);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, self::formatHeaders($headers));
        // 执行cURL会话
        $response = curl_exec($curl);
        curl_close($curl);
        if (!empty($response)) $res = json_decode($response, true);
        return empty($res) ? [] : $res;
    }


}