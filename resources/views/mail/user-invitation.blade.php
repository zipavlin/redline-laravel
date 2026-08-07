<x-mail::message>
# You're invited to Redline

Hi {{ $name }},

An administrator created an account for you on Redline. Click below to set your password and get started.

<x-mail::button :url="$inviteUrl">
Set your password
</x-mail::button>

This link expires in 1 hour. If you didn't expect this invitation, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
