<?php

namespace Zipavlin\Redline\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to a user whose pending registration was approved by an admin, telling
 * them they can now log in. Never contains a plaintext password.
 */
class RegistrationApprovedMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your registration was approved');
    }

    public function content(): Content
    {
        return new Content(markdown: 'redline::mail.registration-approved');
    }
}
