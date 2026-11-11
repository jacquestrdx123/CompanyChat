<?php

namespace App\Support;

/**
 * Sends a broadcast to Reverb without ever failing the request: the change is already saved, and
 * clients catch up on reconnect/refetch. Failures are reported to the log.
 */
class Realtime
{
    /** @param  bool  $toOthers  skip the socket that made the request (X-Socket-ID header) */
    public static function send(object $event, bool $toOthers = true): void
    {
        rescue(function () use ($event, $toOthers) {
            $pending = broadcast($event);
            if ($toOthers) {
                $pending->toOthers();
            }
            unset($pending); // dispatches now, inside rescue()
        }, report: true);
    }
}
