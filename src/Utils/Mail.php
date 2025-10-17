<?php

namespace Spark\Utils;

use PHPMailer\PHPMailer\PHPMailer;
use Spark\Contracts\Utils\MailUtilContract;
use Spark\Facades\Blade;
use Spark\Support\Traits\Macroable;

/**
 * Utility class for sending emails.
 *
 * This class extends the PHPMailer class and adds additional helper methods
 * for sending emails. It also implements the MailUtilContract interface, which
 * defines the methods that can be used to send emails.
 * 
 * @package Spark\Utils
 */
class Mail extends PHPMailer implements MailUtilContract
{
    use Macroable;

    /** @var string The log file path. */
    private string $logFile;

    /**
     * Construct a new instance of the Mail utility class.
     *
     * The constructor takes an array of configuration options as its argument.
     * The configuration options are merged with the environment configuration
     * and are used to configure the mailer instance.
     *
     * @param array $config An associative array of configuration options.
     */
    public function __construct(array $config = [])
    {
        $this->logFile = storage_dir('logs/mail.log');

        // Merger mail config with env config
        $config = array_merge(config('mail', []), $config);

        // Set mailer configuration
        if (isset($config['mailer']['address'])) {
            $this->setFrom($config['mailer']['address'], $config['mailer']['name'] ?? '');
        }

        if (isset($config['reply']['address'])) {
            $this->addReplyTo($config['reply']['address'], $config['reply']['name'] ?? '');
        }

        // Set SMTP configuration
        if (isset($config['smtp']) && ($config['smtp']['enabled'] ?? true)) {
            $this->configureSmtp($config['smtp']);
        }
    }

    /**
     * Disable email logging.
     *
     * This method disables the logging of sent emails.
     *
     * @return self Returns the instance of the class for method chaining.
     */
    public function disableLogging(): self
    {
        $this->config['logging'] = false;
        return $this;
    }

    /**
     * Enable email logging.
     *
     * This method enables the logging of sent emails.
     *
     * @return self Returns the instance of the class for method chaining.
     */
    public function enableLogging(): self
    {
        $this->config['logging'] = true;
        return $this;
    }

    /**
     * Configure the mailer instance using SMTP configuration.
     *
     * This method takes an associative array of SMTP configuration options
     * and sets the corresponding properties on the mailer instance.
     *
     * The configuration array should contain the following keys:
     *
     *   - `host`: The SMTP host.
     *   - `port`: The SMTP port.
     *   - `username`: The SMTP username.
     *   - `password`: The SMTP password.
     *   - `encryption`: The encryption type (either 'tls' or 'ssl').
     *
     * @param array $config An associative array of SMTP configuration options.
     *
     * @return self
     */
    public function configureSmtp(array $config): self
    {
        $this->isSMTP();
        $this->Host = $config['host'];
        $this->Port = $config['port'] ?? 25;

        // Enable SMTP authentication
        if (isset($config['username']) && isset($config['password'])) {
            $this->SMTPAuth = true;
            $this->Username = $config['username'];
            $this->Password = $config['password'];
        }

        // Set encryption type
        $this->SMTPSecure = (isset($config['encryption']) && strtolower($config['encryption']) === 'tls')
            ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;

        return $this; // Return the instance
    }

    /**
     * Set the email body content using a template.
     *
     * This method allows you to set the email body content using a template.
     * The template is rendered using the Hyper\template engine.
     * The engine is configured to look for the template file in the mail
     * directory of the application.
     *
     * @param string $template The name of the template file to render.
     * @param array $context An associative array of context to pass to the template.
     * @return self Returns the instance of the class for method chaining.
     */
    public function view(string $template, array $context = []): self
    {
        return $this->body(
            Blade::render($template, $context),
            true
        );
    }

    /**
     * Set the email body content directly.
     *
     * This method allows you to set the email body content directly.
     * The second parameter determines whether the content is HTML or plain text.
     *
     * @param string $body The content of the email.
     * @param bool $isHtml Whether the content is HTML or plain text.
     * @return self Returns the instance of the class for method chaining.
     */
    public function body(string $body, bool $isHtml = false): self
    {
        $this->isHTML($isHtml);
        $this->CharSet = PHPMailer::CHARSET_UTF8;
        $this->Body = $body;

        return $this;
    }

    /**
     * Set the email subject.
     *
     * This method takes a string argument and sets the email subject.
     * The method returns the instance of the class for method chaining.
     *
     * @param string $subject The subject of the email.
     *
     * @return self Returns the instance of the class for method chaining.
     */
    public function subject(string $subject): self
    {
        $this->Subject = $subject;
        return $this;
    }

    /**
     * Add a recipient to the email.
     *
     * This method takes a string argument and a name argument, and adds the recipient to the email.
     * The method returns the instance of the class for method chaining.
     *
     * @param string $address The email address of the recipient.
     * @param string $name The name of the recipient.
     *
     * @return self Returns the instance of the class for method chaining.
     */
    public function to($address, $name = null): self
    {
        $this->addAddress($address, $name);
        return $this;
    }

    /**
     * Add a carbon copy (CC) recipient to the email.
     *
     * This method takes a string argument and a name argument, and adds the CC recipient to the email.
     * The method returns the instance of the class for method chaining.
     *
     * @param string $address The email address of the CC recipient.
     * @param string $name The name of the CC recipient.
     *
     * @return self Returns the instance of the class for method chaining.
     */
    public function cc($address, $name = null): self
    {
        $this->addCC($address, $name);
        return $this;
    }

    /**
     * Add a blind carbon copy (BCC) recipient to the email.
     *
     * This method takes an email address and an optional name, and adds the BCC recipient to the email.
     * The method returns the instance of the class for method chaining.
     *
     * @param string $address The email address of the BCC recipient.
     * @param string|null $name The name of the BCC recipient.
     *
     * @return self Returns the instance of the class for method chaining.
     */

    public function bcc($address, $name = null): self
    {
        $this->addBCC($address, $name);
        return $this;
    }

    /**
     * Set the sender of the email.
     *
     * This method takes an email address and an optional name, and sets the sender of the email.
     * The method returns the instance of the class for method chaining.
     *
     * @param string $address The email address of the sender.
     * @param string|null $name The name of the sender.
     *
     * @return self Returns the instance of the class for method chaining.
     */
    public function mailer($address, $name = null): self
    {
        $this->setFrom($address, $name);
        return $this;
    }

    /**
     * Set the reply-to email address and name.
     *
     * This method takes an email address and an optional name, and sets the reply-to
     * email address and name of the email.
     * The method returns the instance of the class for method chaining.
     *
     * @param string $address The email address of the reply-to.
     * @param string|null $name The name of the reply-to.
     *
     * @return self Returns the instance of the class for method chaining.
     */
    public function reply($address, $name = null): self
    {
        $this->addReplyTo($address, $name);
        return $this;
    }

    /**
     * Send the email.
     *
     * This method sends the email and returns true if it was sent successfully,
     * and false otherwise.
     *
     * @return bool Returns true if the email was sent successfully, and false otherwise.
     */
    public function send(): bool
    {
        $status = parent::send();
        $this->logEmailSent($status);
        return $status;
    }

    /**
     * Create a copy of the mailer instance.
     *
     * @return self A new instance that is a copy of the current instance.
     */
    public function copy(): self
    {
        return clone $this;
    }

    /**
     * Log the email sending status.
     *
     * This method logs the status of the email sending operation, including
     * whether it was successful or not, the recipient addresses, the subject,
     * and the duration of the operation.
     *
     * @param bool $status The status of the email sending operation (true for success, false for failure).
     */
    private function logEmailSent(bool $status): void
    {
        if (!($this->config['logging'] ?? true)) {
            return; // Logging is disabled
        }

        $maxFileSize = 5 * 1024 * 1024; // 5 MB in bytes

        // Check if log file exists and its size and rotate if it exceeds the max size.
        if (is_file($this->logFile) && filesize($this->logFile) >= $maxFileSize) {
            rename($this->logFile, $this->logFile . '.' . date('Y-m-d_H-i-s'));
        }

        $logEntry = sprintf(
            "[%s] Email to: %s | Subject: %s | Status: %s\n",
            date('Y-m-d H:i:s'),
            implode(', ', array_keys($this->getToAddresses())),
            $this->Subject,
            $status ? 'Sent' : 'Failed'
        );

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}