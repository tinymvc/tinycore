<?php

namespace Spark\Facades;

use Spark\Utils\Mail as BaseMail;

/**
 * Facade Mail
 * 
 * This class serves as a facade for the mail system, providing a static interface to the underlying Mail class.
 * It allows easy access to mail methods such as sending emails, retrieving mail settings, and managing mail sessions.
 * 
 * @method static BaseMail configure(callable $callback)
 * @method static BaseMail configureSmtp(array $config)
 * @method static BaseMail view(string $template, array $context = [])
 * @method static BaseMail body(string $body, bool $isHtml = false)
 * @method static BaseMail subject(string $subject)
 * @method static BaseMail to($address, $name = null)
 * @method static BaseMail cc($address, $name = null)
 * @method static BaseMail bcc($address, $name = null)
 * @method static BaseMail attachFile($path, $name = null)
 * @method static BaseMail mailer($address, $name = null)
 * @method static BaseMail from($address, $name = null)
 * @method static BaseMail reply($address, $name = null)
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
