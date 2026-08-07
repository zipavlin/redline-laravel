<x-mail::message>
# Reset your password

Hi {{ $name }},

We received a request to set a new password for your account. Click below to choose one.

<x-mail::button :url="$resetUrl">
Set a new password
</x-mail::button>

This link expires in 1 hour. If you didn't request this, you can safely ignore this email — your password won't change.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
