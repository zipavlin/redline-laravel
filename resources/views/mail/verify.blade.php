<x-mail::message>
# Verify your email

Hi {{ $name }},

Thanks for registering. Please confirm your email address to activate your account.

<x-mail::button :url="$verifyUrl">
Verify email
</x-mail::button>

This link expires in 24 hours. If you didn't request this, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
