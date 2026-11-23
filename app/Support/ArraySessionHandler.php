<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\InteractsWithTime;
use SessionHandlerInterface;

/**
 * PHP 8.6 deprecates session handlers that do not implement create_sid().
 * Laravel's built-in array handler is still missing it, so tests and any
 * array-session deployment would fail as soon as the handler class is loaded.
 * This project-level handler keeps the same in-memory behaviour and implements
 * the forward-compatible method.
 */
final class ArraySessionHandler implements SessionHandlerInterface
{
    use InteractsWithTime;

    /** @var array<string, array{data: string, time: int}> */
    private array $storage = [];

    public function __construct(private readonly int $minutes) {}

    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): string|false
    {
        if (! isset($this->storage[$sessionId])) {
            return '';
        }

        $session = $this->storage[$sessionId];

        $expiration = $this->calculateExpiration($this->minutes * 60);

        if (isset($session['time']) && $session['time'] >= $expiration) {
            return $session['data'];
        }

        return '';
    }

    public function write(string $sessionId, string $data): bool
    {
        $this->storage[$sessionId] = [
            'data' => $data,
            'time' => $this->currentTime(),
        ];

        return true;
    }

    public function destroy(string $sessionId): bool
    {
        unset($this->storage[$sessionId]);

        return true;
    }

    public function gc(int $lifetime): int|false
    {
        $expiration = $this->calculateExpiration($lifetime);
        $deletedSessions = 0;

        foreach ($this->storage as $sessionId => $session) {
            if ($session['time'] < $expiration) {
                unset($this->storage[$sessionId]);
                $deletedSessions++;
            }
        }

        return $deletedSessions;
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(20));
    }

    public function validateId(string $sessionId): bool
    {
        return $sessionId !== '';
    }

    private function calculateExpiration(int $seconds): int
    {
        return $this->currentTime() - $seconds;
    }
}
