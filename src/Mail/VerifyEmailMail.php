<?php

namespace Zipavlin\Redline\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to a newly registered user (DomainAuthProvider) with a signed link that
 * verifies their email and activates the account.
 */
class VerifyEmailMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $verifyUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your email');
    }

    public function content(): Content
    {
        return new Content(markdown: 'redline::mail.verify');
    }
}
