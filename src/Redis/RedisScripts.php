<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Redis;

/**
 * Versioned Lua scripts. SHA cache is reloaded on NOSCRIPT.
 */
final class RedisScripts
{
    public const VERSION = '3';

    public const ENQUEUE = <<<'LUA'
local jobKey = KEYS[1]
local indexKey = KEYS[2]
local queueSet = KEYS[3]
local wakeup = KEYS[4]
local envelope = ARGV[1]
local state = ARGV[2]
local available = ARGV[3]
local score = ARGV[4]
local queue = ARGV[5]
local jobId = ARGV[6]
local uniqueKey = ARGV[8]
if uniqueKey ~= nil and uniqueKey ~= '' then
  local existing = redis.call('GET', uniqueKey)
  if existing then
    return {0, existing}
  end
  redis.call('SET', uniqueKey, jobId)
end
redis.call('HSET', jobKey, 'envelope', envelope, 'state', state, 'available_at', available, 'queue', queue, 'unique_redis_key', uniqueKey or '', 'unique_ttl', ARGV[9] or '0')
if tonumber(available) > tonumber(ARGV[7]) then
  redis.call('ZADD', KEYS[5], available, jobId)
else
  redis.call('ZADD', indexKey, score, jobId)
end
redis.call('SADD', queueSet, queue)
redis.call('RPUSH', wakeup, '1')
redis.call('LTRIM', wakeup, -8, -1)
return {1, jobId}
LUA;

    public const RESERVE = <<<'LUA'
local ns = ARGV[1]
local queue = ARGV[2]
local now = tonumber(ARGV[3])
local lease = tonumber(ARGV[4])
local worker = ARGV[5]
local token = ARGV[6]
local concLimit = tonumber(ARGV[7])
local rateKey = ARGV[8]
local rateCap = tonumber(ARGV[9])
local rateRefill = tonumber(ARGV[10])
local ready = ns .. ':ready:' .. queue
local delayed = ns .. ':delayed:' .. queue
local reserved = ns .. ':reserved'
local conc = ns .. ':conc:' .. queue
local dead = ns .. ':dead'

local function jobKey(id)
  return ns .. ':job:' .. id
end

local function score(priority, available)
  local p = tonumber(priority) or 0
  if p > 4999 then p = 4999 end
  if p < -5000 then p = -5000 end
  return (5000 - p) * 10000000000 + tonumber(available)
end

local function parse(envelope)
  return cjson.decode(envelope)
end

local due = redis.call('ZRANGEBYSCORE', delayed, '-inf', now, 'LIMIT', 0, 200)
for _, id in ipairs(due) do
  local raw = redis.call('HGET', jobKey(id), 'envelope')
  redis.call('ZREM', delayed, id)
  if raw then
    local env = parse(raw)
    local av = tonumber(redis.call('HGET', jobKey(id), 'available_at') or now)
    redis.call('ZADD', ready, score(env['priority'] or 0, av), id)
  end
end

local expired = redis.call('ZRANGEBYSCORE', reserved, '-inf', now, 'LIMIT', 0, 100)
for _, id in ipairs(expired) do
  local raw = redis.call('HGET', jobKey(id), 'envelope')
  redis.call('ZREM', reserved, id)
  redis.call('ZREM', conc, id)
  if raw then
    local env = parse(raw)
    local attempt = tonumber(env['attempt'] or 0)
    local maxa = tonumber(env['max_attempts'] or 3)
    local q = env['queue'] or queue
    if attempt >= maxa then
      env['state'] = 'dead'
      redis.call('HSET', jobKey(id), 'envelope', cjson.encode(env), 'state', 'dead', 'token', '', 'worker_id', '')
      redis.call('ZADD', dead, now, id)
    else
      env['state'] = 'pending'
      redis.call('HSET', jobKey(id), 'envelope', cjson.encode(env), 'state', 'pending', 'token', '', 'worker_id', '')
      local av = tonumber(redis.call('HGET', jobKey(id), 'available_at') or now)
      if av > now then
        redis.call('ZADD', ns .. ':delayed:' .. q, av, id)
      else
        redis.call('ZADD', ns .. ':ready:' .. q, score(env['priority'] or 0, av), id)
      end
    end
  end
end

if concLimit > 0 then
  redis.call('ZREMRANGEBYSCORE', conc, '-inf', now)
  if redis.call('ZCARD', conc) >= concLimit then
    return { 'throttled', 'concurrency' }
  end
end

local candidates = redis.call('ZRANGE', ready, 0, 31)
for _, id in ipairs(candidates) do
  local raw = redis.call('HGET', jobKey(id), 'envelope')
  if not raw then
    redis.call('ZREM', ready, id)
  else
    local env = parse(raw)
    if env['state'] == 'pending' then
      local attempt = tonumber(env['attempt'] or 0)
      local maxa = tonumber(env['max_attempts'] or 3)
      if attempt >= maxa then
        env['state'] = 'dead'
        redis.call('ZREM', ready, id)
        redis.call('HSET', jobKey(id), 'envelope', cjson.encode(env), 'state', 'dead')
        redis.call('ZADD', dead, now, id)
      else
        local useRateKey = rateKey
        local useRateCap = rateCap
        local useRateRefill = rateRefill
        local meta = env['metadata'] or {}
        local jobRate = meta['_rate']
        if type(jobRate) == 'table' then
          if jobRate['key'] then useRateKey = tostring(jobRate['key']) end
          if jobRate['capacity'] then useRateCap = tonumber(jobRate['capacity']) or useRateCap end
          if jobRate['refill_per_second'] then useRateRefill = tonumber(jobRate['refill_per_second']) or useRateRefill end
        end
        if useRateKey ~= '' and useRateCap > 0 then
          local rkey = ns .. ':rate:' .. useRateKey
          local tokens = tonumber(redis.call('HGET', rkey, 'tokens') or useRateCap)
          local ts = tonumber(redis.call('HGET', rkey, 'ts') or now)
          local elapsed = now - ts
          if elapsed < 0 then elapsed = 0 end
          tokens = math.min(useRateCap, tokens + elapsed * useRateRefill)
          if tokens < 1 then
            local wait = (1 - tokens) / useRateRefill
            if wait < 1 then wait = 1 end
            redis.call('HSET', rkey, 'tokens', tokens, 'ts', now)
            return { 'throttled', 'rate', tostring(math.ceil(wait)) }
          end
          redis.call('HSET', rkey, 'tokens', tokens - 1, 'ts', now)
        end
        attempt = attempt + 1
        env['attempt'] = attempt
        env['state'] = 'reserved'
        redis.call('ZREM', ready, id)
        redis.call('ZADD', reserved, lease, id)
        if concLimit > 0 then
          redis.call('ZADD', conc, lease, id)
        end
        redis.call('HSET', jobKey(id), 'envelope', cjson.encode(env), 'state', 'reserved', 'token', token, 'worker_id', worker, 'lease_expires', tostring(lease), 'attempt', tostring(attempt))
        return { 'ok', cjson.encode(env), token, tostring(now), tostring(lease), tostring(attempt) }
      end
    else
      redis.call('ZREM', ready, id)
    end
  end
end
return { 'empty' }
LUA;

    public const OWNED = <<<'LUA'
local jobKey = KEYS[1]
local token = ARGV[1]
local expected = redis.call('HGET', jobKey, 'token')
if expected ~= token then
  return 0
end
return 1
LUA;

    public const ACK = <<<'LUA'
local jobKey = KEYS[1]
local reserved = KEYS[2]
local completed = KEYS[3]
local conc = KEYS[4]
local token = ARGV[1]
local jobId = ARGV[2]
local now = ARGV[3]
if redis.call('HGET', jobKey, 'token') ~= token then
  return 0
end
local raw = redis.call('HGET', jobKey, 'envelope')
if not raw then
  return 0
end
local env = cjson.decode(raw)
env['state'] = 'completed'
redis.call('HSET', jobKey, 'envelope', cjson.encode(env), 'state', 'completed', 'token', '', 'worker_id', '', 'completed_at', now)
redis.call('ZREM', reserved, jobId)
redis.call('ZREM', conc, jobId)
redis.call('ZADD', completed, now, jobId)
local uk = redis.call('HGET', jobKey, 'unique_redis_key')
if uk and uk ~= '' then
  local ttl = tonumber(redis.call('HGET', jobKey, 'unique_ttl') or '0')
  if ttl > 0 then
    redis.call('EXPIRE', uk, ttl)
  else
    redis.call('DEL', uk)
  end
end
return 1
LUA;

    public const RELEASE = <<<'LUA'
local jobKey = KEYS[1]
local reserved = KEYS[2]
local conc = KEYS[3]
local index = KEYS[4]
local token = ARGV[1]
local jobId = ARGV[2]
local available = tonumber(ARGV[3])
local score = ARGV[4]
local now = tonumber(ARGV[5])
if redis.call('HGET', jobKey, 'token') ~= token then
  return 0
end
local raw = redis.call('HGET', jobKey, 'envelope')
if not raw then
  return 0
end
local env = cjson.decode(raw)
env['state'] = 'pending'
env['available_at'] = ARGV[6]
redis.call('HSET', jobKey, 'envelope', cjson.encode(env), 'state', 'pending', 'token', '', 'worker_id', '', 'available_at', tostring(available))
redis.call('ZREM', reserved, jobId)
redis.call('ZREM', conc, jobId)
if available > now then
  redis.call('ZADD', KEYS[5], available, jobId)
else
  redis.call('ZADD', index, score, jobId)
end
return 1
LUA;

    public const SETTLE = <<<'LUA'
local jobKey = KEYS[1]
local reserved = KEYS[2]
local conc = KEYS[3]
local attempts = KEYS[4]
local dest = KEYS[5]
local delayedOrReady = KEYS[6]
local token = ARGV[1]
local jobId = ARGV[2]
local nextState = ARGV[3]
local available = tonumber(ARGV[4])
local score = ARGV[5]
local now = tonumber(ARGV[6])
local record = ARGV[7]
local destScore = ARGV[8]
if redis.call('HGET', jobKey, 'token') ~= token then
  return 0
end
local raw = redis.call('HGET', jobKey, 'envelope')
if not raw then
  return 0
end
local env = cjson.decode(raw)
env['state'] = nextState
if nextState == 'pending' then
  env['available_at'] = ARGV[9]
end
redis.call('HSET', jobKey, 'envelope', cjson.encode(env), 'state', nextState, 'token', '', 'worker_id', '', 'available_at', tostring(available))
redis.call('ZREM', reserved, jobId)
redis.call('ZREM', conc, jobId)
redis.call('RPUSH', attempts, record)
if nextState == 'dead' or nextState == 'failed' then
  redis.call('ZADD', dest, destScore, jobId)
  local uk = redis.call('HGET', jobKey, 'unique_redis_key')
  if uk and uk ~= '' then
    local ttl = tonumber(redis.call('HGET', jobKey, 'unique_ttl') or '0')
    if ttl > 0 then
      redis.call('EXPIRE', uk, ttl)
    else
      redis.call('DEL', uk)
    end
  end
else
  if available > now then
    redis.call('ZADD', delayedOrReady, available, jobId)
  else
    redis.call('ZADD', dest, score, jobId)
  end
end
return 1
LUA;

    public const EXTEND = <<<'LUA'
local jobKey = KEYS[1]
local reserved = KEYS[2]
local conc = KEYS[3]
local token = ARGV[1]
local jobId = ARGV[2]
local lease = ARGV[3]
if redis.call('HGET', jobKey, 'token') ~= token then
  return 0
end
redis.call('HSET', jobKey, 'lease_expires', lease)
redis.call('ZADD', reserved, lease, jobId)
redis.call('ZADD', conc, lease, jobId)
return 1
LUA;

    public const REVIVE = <<<'LUA'
local jobKey = KEYS[1]
local dead = KEYS[2]
local ready = KEYS[3]
local jobId = ARGV[1]
local now = ARGV[2]
local score = ARGV[3]
local raw = redis.call('HGET', jobKey, 'envelope')
if not raw then
  return 0
end
local env = cjson.decode(raw)
if env['state'] ~= 'dead' and env['state'] ~= 'failed' then
  return -1
end
local uniqueKey = ARGV[5]
if uniqueKey ~= nil and uniqueKey ~= '' then
  local existing = redis.call('GET', uniqueKey)
  if existing and existing ~= jobId then
    return -2
  end
  redis.call('SET', uniqueKey, jobId)
end
env['state'] = 'pending'
env['attempt'] = 0
local meta = env['metadata'] or {}
meta['_replay'] = tonumber(meta['_replay'] or 0) + 1
env['metadata'] = meta
env['available_at'] = ARGV[4]
redis.call('HSET', jobKey, 'envelope', cjson.encode(env), 'state', 'pending', 'attempt', '0', 'available_at', now, 'token', '')
redis.call('ZREM', dead, jobId)
redis.call('ZADD', ready, score, jobId)
return 1
LUA;

    public const LOCK_RELEASE = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    public const LOCK_EXTEND = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('PEXPIRE', KEYS[1], ARGV[2])
end
return 0
LUA;

    public const UNIQUE_RELEASE = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  local ttl = tonumber(ARGV[2] or '0')
  if ttl > 0 then
    return redis.call('EXPIRE', KEYS[1], ttl)
  end
  return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    public const IDEMPOTENCY_BEGIN = <<<'LUA'
local key = KEYS[1]
local ownerKey = KEYS[2]
if redis.call('EXISTS', key) == 1 then
  return {
    0,
    redis.call('HGET', key, 'status'),
    redis.call('HGET', key, 'result') or '',
  }
end
redis.call('HSET', key, 'status', 'started', 'owner', ARGV[1], 'key', ARGV[2], 'started_at', ARGV[3], 'expires_at', tostring(tonumber(ARGV[3]) + tonumber(ARGV[4])), 'result', '')
redis.call('EXPIRE', key, tonumber(ARGV[4]))
redis.call('SET', ownerKey, key)
redis.call('EXPIRE', ownerKey, tonumber(ARGV[4]))
return {1, 'started', ''}
LUA;

    public const IDEMPOTENCY_COMPLETE = <<<'LUA'
local key = KEYS[1]
local ownerKey = KEYS[2]
if redis.call('HGET', key, 'owner') ~= ARGV[1] or redis.call('HGET', key, 'status') ~= 'started' then
  return 0
end
redis.call('HSET', key, 'status', 'completed', 'result', ARGV[2], 'completed_at', ARGV[3])
redis.call('EXPIRE', key, tonumber(ARGV[4]))
redis.call('EXPIRE', ownerKey, tonumber(ARGV[4]))
return 1
LUA;

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'enqueue' => self::ENQUEUE,
            'reserve' => self::RESERVE,
            'ack' => self::ACK,
            'release' => self::RELEASE,
            'settle' => self::SETTLE,
            'extend' => self::EXTEND,
            'revive' => self::REVIVE,
            'lock_release' => self::LOCK_RELEASE,
            'lock_extend' => self::LOCK_EXTEND,
            'unique_release' => self::UNIQUE_RELEASE,
            'idemp_begin' => self::IDEMPOTENCY_BEGIN,
            'idemp_complete' => self::IDEMPOTENCY_COMPLETE,
        ];
    }
}
