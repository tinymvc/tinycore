<?php

namespace Spark\Facades;

use Spark\Utils\Mail as BaseMail;

/**
 * Facade Mail
 * 
 * This class serves as a facade for the mail system, providing a static interface to the underlying Mail class.
 * It allows easy access to mail methods such as sending emails, retrieving mail settings, and managing mail sessions.
 * 
 * @method static bool send()
 * @method static BaseMail make(array $config = [])
 * @method static BaseMail copy()
 * @method static BaseMail reset()
 * @method static BaseMail smtpKeepAlive()
 * @method static BaseMail configure(callable $callback)
 * @method static BaseMail configureSmtp(array $config)
 * @method static BaseMail view(string $template, array $context = [])
 * @method static BaseMail body(string $body, bool $isHtml = false)
 * @method static BaseMail subject(string $subject)
 * @method static BaseMail to($address, $name = null)
 * @method static BaseMail cc($address, $name = null)
 * @method static BaseMail bcc($address, $name = null)
 * @method static BaseMail attachFile($path, $name = null)
 * @method static BaseMail from($address, $name = null)
 * @method static BaseMail mailer($address, $name = null)
 * @method static BaseMail reply($address, $name = null)
 * @method static BaseMail withHeader(string $name, string $value)
 * @method static BaseMail withHeaders(array $headers)
 * @method static BaseMail addAddress(string $address, string $name = '')
 * @method static BaseMail addCC(string $address, string $name = '')
 * @method static BaseMail addBCC(string $address, string $name = '')
 * @method static BaseMail addReplyTo(string $address, string $name = '')
 * @method static BaseMail setFrom(string $address, string $name = '', bool $auto = true)
 * @method static BaseMail addAttachment(string $path, string $name = '', string $encoding = 'base64', string $type = '', string $disposition = 'attachment')
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Mail extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseMail::class;
    }
}
