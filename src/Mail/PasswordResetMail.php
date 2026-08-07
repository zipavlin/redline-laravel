<?php

namespace Zipavlin\Redline\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Carries a temporary signed link that lets the user set a new password.
 * Used by both the self-service "forgot password" flow and admin-triggered resets.
 * Never contains a plaintext password.
 */
class PasswordResetMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'redline::mail.password-reset');
    }
}
