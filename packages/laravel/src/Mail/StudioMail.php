<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mail;

use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/** A dedicated named mailer never changes the host's default transport. */
final class StudioMail
{
    public static function name(): ?string
    {
        $mode = config('media-studio.mail.mode', 'inherit');
        if ($mode === 'inherit') {
            return null;
        }
        if ($mode === 'mailer') {
            $name = config('media-studio.mail.mailer');
            if (! is_string($name) || ! config('mail.mailers.'.$name)) {
                throw new InvalidArgumentException('Configure an existing Studio mailer.');
            }

            return $name;
        }
        if ($mode !== 'smtp') {
            throw new InvalidArgumentException('Unsupported Studio mail mode.');
        }
        $smtp = config('media-studio.mail.smtp', []);
        if (! is_string($smtp['host'] ?? null) || trim($smtp['host']) === '' || ! in_array($smtp['scheme'] ?? 'smtp', ['smtp', 'smtps'], true)) {
            throw new InvalidArgumentException('Configure a valid SMTP host and scheme.');
        }
        config()->set('mail.mailers.fms_studio', ['transport' => 'smtp', 'host' => $smtp['host'], 'port' => (int) ($smtp['port'] ?? 587), 'scheme' => $smtp['scheme'] ?? 'smtp', 'username' => $smtp['username'] ?? null, 'password' => $smtp['password'] ?? null, 'timeout' => 15, 'auto_tls' => true, 'require_tls' => true]);

        return 'fms_studio';
    }

    public static function notification($message)
    {
        $message->mailer(self::name());
        $from = config('media-studio.mail.from.address');
        if (is_string($from) && $from !== '') {
            $message->from($from, config('media-studio.mail.from.name', 'Media Studio'));
        }

        return $message;
    }

    public static function raw(string $text, callable $callback): void
    {
        $name = self::name();
        $wrapped = function ($message) use ($callback): void {
            $from = config('media-studio.mail.from.address');
            if (is_string($from) && $from !== '') {
                $message->from($from, config('media-studio.mail.from.name', 'Media Studio'));
            }
            $callback($message);
        };
        if ($name === null) {
            Mail::raw($text, $wrapped);
        } else {
            Mail::mailer($name)->raw($text, $wrapped);
        }
    }
}
