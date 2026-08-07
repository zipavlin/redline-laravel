<?php

namespace Zipavlin\Redline\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent when an admin creates a user without an initial password. Carries a
 * signed link to the dashboard reset-password page (same machinery as
 * forgot-password). Never contains a plaintext password.
 */
class UserInvitationMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $inviteUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to Redline");
    }

    public function content(): Content
    {
        return new Content(markdown: 'redline::mail.user-invitation');
    }
}
