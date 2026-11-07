<?php

namespace App\AI\Support;

use Generator;
use Psr\Http\Message\StreamInterface;

final class SseParser
{
    public function parse(StreamInterface $stream): Generator
    {
        $buffer = '';
        $event = null;
        $data = [];
        $emptyReads = 0;

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                if (++$emptyReads >= 3) {
                    break;
                }

                continue;
            }

            $emptyReads = 0;
            $buffer .= $chunk;

            while (($line = $this->nextLine($buffer)) !== null) {
                yield from $this->consumeLine($line, $event, $data);
            }
        }

        while (($line = $this->nextLine($buffer, flush: true)) !== null) {
            yield from $this->consumeLine($line, $event, $data);
        }

        if ($data !== []) {
            yield ['event' => $event, 'data' => implode("\n", $data)];
        }
    }

    private function consumeLine(string $line, ?string &$event, array &$data): Generator
    {
        if ($line === '') {
            if ($data !== []) {
                yield ['event' => $event, 'data' => implode("\n", $data)];
            }

            $event = null;
            $data = [];

            return;
        }

        if (str_starts_with($line, ':')) {
            return;
        }

        [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
        $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

        if ($field === 'event') {
            $event = $value;
        } elseif ($field === 'data') {
            $data[] = $value;
        }
    }

    private function nextLine(string &$buffer, bool $flush = false): ?string
    {
        $length = strlen($buffer);

        for ($index = 0; $index < $length; $index++) {
            if ($buffer[$index] === "\n") {
                $line = substr($buffer, 0, $index);
                $buffer = substr($buffer, $index + 1);

                return $line;
            }

            if ($buffer[$index] === "\r") {
                if ($index === $length - 1 && ! $flush) {
                    return null;
                }

                $line = substr($buffer, 0, $index);
                $skip = $index + 1 < $length && $buffer[$index + 1] === "\n" ? 2 : 1;
                $buffer = substr($buffer, $index + $skip);

                return $line;
            }
        }

        return null;
    }
}
