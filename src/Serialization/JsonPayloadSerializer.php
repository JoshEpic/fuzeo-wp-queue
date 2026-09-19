<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Serialization;

use Fuzeo\Queue\Exceptions\SerializationException;

final class JsonPayloadSerializer implements PayloadSerializer
{
    public function __construct(private readonly PayloadLimits $limits = new PayloadLimits())
    {
    }

    public function normalize(array $payload): array
    {
        $this->assertObjectMap($payload, '$');
        $normalized = $this->walk($payload, 0, '$');
        $this->assertSize($this->encode($normalized));

        /** @var array<string, mixed> $normalized */
        return $normalized;
    }

    public function encode(array $payload): string
    {
        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (\JsonException $exception) {
            throw new SerializationException('Unable to encode payload as JSON: ' . $exception->getMessage(), 0, $exception);
        }

        $this->assertSize($json);

        return $json;
    }

    public function decode(string $json): array
    {
        if ($json === '') {
            throw new SerializationException('Payload JSON is empty.');
        }

        $this->assertSize($json);

        try {
            /** @var int<1, max> $depth */
            $depth = max(1, $this->limits->maxDepth);
            $decoded = json_decode($json, true, $depth, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SerializationException('Malformed JSON payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new SerializationException('Payload JSON must decode to an object/map, not a scalar or list root.');
        }

        return $this->normalize($decoded);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function walk(mixed $value, int $depth, string $path): mixed
    {
        if ($depth > $this->limits->maxDepth) {
            throw new SerializationException('Payload exceeds maximum depth of ' . $this->limits->maxDepth . ' at ' . $path . '.');
        }

        if (is_object($value)) {
            throw new SerializationException(
                'Payload at ' . $path . ' contains an object of type ' . $value::class
                . '. Queue identities, not live application state. Pass IDs and primitives only.'
            );
        }

        if (is_resource($value)) {
            throw new SerializationException('Payload at ' . $path . ' contains a resource, which cannot be queued.');
        }

        if (is_array($value)) {
            $isList = function_exists('array_is_list') ? array_is_list($value) : $this->isList($value);
            $out = [];
            foreach ($value as $key => $item) {
                if (!is_int($key) && !is_string($key)) {
                    throw new SerializationException('Payload keys must be strings or integers at ' . $path . '.');
                }
                if (is_string($key) && $key === '') {
                    throw new SerializationException('Payload contains an empty string key at ' . $path . '.');
                }
                $childPath = $path . '.' . $key;
                $out[$key] = $this->walk($item, $depth + 1, $childPath);
            }

            if (!$isList) {
                foreach (array_keys($out) as $key) {
                    if (!is_string($key)) {
                        throw new SerializationException('Associative payload maps must use string keys at ' . $path . '.');
                    }
                }
            }

            return $out;
        }

        if (is_string($value) && strlen($value) > $this->limits->maxStringBytes) {
            throw new SerializationException(
                'Payload string at ' . $path . ' exceeds ' . $this->limits->maxStringBytes . ' bytes.'
            );
        }

        if (is_string($value) && !$this->isUtf8($value)) {
            throw new SerializationException('Payload at ' . $path . ' is not valid UTF-8.');
        }

        if (is_float($value) && !is_finite($value)) {
            throw new SerializationException('Payload at ' . $path . ' contains a non-finite float.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new SerializationException('Unsupported PHP type at ' . $path . ': ' . get_debug_type($value) . '.');
    }

    /**
     * @param array<mixed> $payload
     */
    private function assertObjectMap(array $payload, string $path): void
    {
        foreach (array_keys($payload) as $key) {
            if (is_int($key)) {
                throw new SerializationException(
                    'Root payload must be a JSON object/map with string keys, not a list (' . $path . ').'
                );
            }
        }
    }

    private function assertSize(string $json): void
    {
        if (strlen($json) > $this->limits->maxBytes) {
            throw new SerializationException(
                'Payload exceeds maximum size of ' . $this->limits->maxBytes . ' bytes.'
            );
        }
    }

    private function isUtf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        $i = 0;
        foreach ($value as $key => $_) {
            if ($key !== $i) {
                return false;
            }
            $i++;
        }

        return true;
    }
}
