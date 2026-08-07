<?php

namespace Zipavlin\Redline\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notifies developers/admins that a new bug report was filed.
 */
class NewReportMail extends Mailable
{
    public function __construct(
        public string $reporterName,
        public string $title,
        public string $reportUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New bug report: '.$this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'redline::mail.new-report');
    }
}
