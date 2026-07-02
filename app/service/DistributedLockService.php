<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Log;

class DistributedLockService
{
    private $redis;

    public function __construct()
    {
        // 获取 Redis 原生对象
        $this->redis = Cache::handler();
    }

    /**
     * 尝试获取锁（非阻塞）
     * @param string $key 锁的键名
     * @param string $token 唯一标识（用于安全解锁）
     * @param int $ttl 锁过期时间（秒）
     * @return bool
     */
    public function lock($key, $token, $ttl = 10)
    {
        // 原子操作：SET key token NX EX seconds
        return $this->redis->set($key, $token, ['nx', 'ex' => $ttl]);
    }

    /**
     * 尝试获取锁（阻塞模式，带超时）
     * @param string $key
     * @param string $token
     * @param int $ttl 锁过期时间（秒）
     * @param int $timeout 获取锁超时时间（毫秒）
     * @return bool
     */
    public function lockBlocking($key, $token, $ttl = 10, $timeout = 3000)
    {
        $startTime = microtime(true) * 1000;

        while (true) {
            $result = $this->redis->set($key, $token, ['nx', 'ex' => $ttl]);

            if ($result) {
                return true;
            }

            // 检查是否超时
            if ((microtime(true) * 1000) - $startTime > $timeout) {
                return false;
            }

            // 等待 50ms 后重试
            usleep(50000);
        }
    }

    /**
     * 释放锁（使用 Lua 脚本保证原子性）
     * @param string $key
     * @param string $token
     * @return bool
     */
    public function unlock($key, $token)
    {
        $luaScript = <<<LUA
            if redis.call('GET', KEYS[1]) == ARGV[1] then
                return redis.call('DEL', KEYS[1])
            else
                return 0
            end
LUA;

        $result = $this->redis->eval($luaScript, [$key, $token], 1);
        return $result == 1;
    }

    /**
     * 自动续期（防止业务执行超时导致锁自动释放）
     * @param string $key
     * @param string $token
     * @param int $ttl
     */
    public function renew($key, $token, $ttl = 10)
    {
        $luaScript = <<<LUA
            if redis.call('GET', KEYS[1]) == ARGV[1] then
                return redis.call('EXPIRE', KEYS[1], ARGV[2])
            else
                return 0
            end
LUA;

        $result = $this->redis->eval($luaScript, [$key, $token, $ttl], 1);
        return $result == 1;
    }
}