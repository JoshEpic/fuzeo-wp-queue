<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Redis;

use Fuzeo\Queue\Exceptions\DriverException;

final class ScriptCache
{
    /** @var array<string, string> */
    private array $shas = [];

    public function __construct(private readonly RedisClient $redis)
    {
    }

    /**
     * @param list<string> $keys
     * @param list<mixed> $argv
     */
    public function run(string $name, string $script, array $keys, array $argv): mixed
    {
        try {
            if (!isset($this->shas[$name])) {
                $this->shas[$name] = $this->redis->scriptLoad($script);
            }

            return $this->redis->evalSha($this->shas[$name], $keys, $argv);
        } catch (DriverException $exception) {
            if (!str_contains(strtolower($exception->getMessage()), 'noscript')) {
                throw $exception;
            }
            $this->shas[$name] = $this->redis->scriptLoad($script);

            return $this->redis->evalSha($this->shas[$name], $keys, $argv);
        }
    }
}
