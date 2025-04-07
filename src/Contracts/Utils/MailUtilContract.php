<?php

namespace Spark\Contracts\Utils;

/**
 * Interface for email utility functionality.
 * 
 * This contract defines the methods required for sending emails,
 * including setting the subject, body, recipients, and other email properties.
 */
interface MailUtilContract
{
    /**
     * Set the email subject.
     * 
     * @param string $subject The subject of the email.
     * @return self Returns the instance for method chaining.
     */
    public function subject(string $subject): self;

    /**
     * Set the email body content.
     * 
     * @param string $body The content of the email.
     * @param bool $isHtml Whether the content is HTML or plain text.
     * @return self Returns the instance for method chaining.
     */
    public function body(string $body, bool $isHtml): self;

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
    public function view(string $template, array $context = []): self;

    /**
     * Add a carbon copy (CC) recipient to the email.
     * 
     * @param string $address The email address of the CC recipient.
     * @param string|null $name The name of the CC recipient.
     * @return self Returns the instance for method chaining.
     */
    public function cc($address, $name = null): self;

    /**
     * Add a blind carbon copy (BCC) recipient to the email.
     * 
     * @param string $address The email address of the BCC recipient.
     * @param string|null $name The name of the BCC recipient.
     * @return self Returns the instance for method chaining.
     */
    public function bcc($address, $name = null): self;

    /**
     * Add a recipient to the email.
     * 
     * @param string $address The email address of the recipient.
     * @param string|null $name The name of the recipient.
     * @return self Returns the instance for method chaining.
     */
    public function to($address, $name = null): self;

    /**
     * Set the sender of the email.
     * 
     * @param string $address The email address of the sender.
     * @param string|null $name The name of the sender.
     * @return self Returns the instance for method chaining.
     */
    public function mailer($address, $name = null): self;

    /**
     * Set the reply-to email address and name.
     * 
     * @param string $address The email address for replies.
     * @param string|null $name The name for replies.
     * @return self Returns the instance for method chaining.
     */
    public function reply($address, $name = null): self;

    /**
     * Send the email.
     * 
     * @return bool Returns true if the email was sent successfully, false otherwise.
     */
    public function send(): bool;
}
