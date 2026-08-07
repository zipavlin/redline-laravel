<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
<svg width="52" height="53" viewBox="0 0 52 53" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M30.1131 35.592C31.8624 36.5307 33.8891 37 36.1931 37H47.3931V29.768H36.7691C35.4891 29.768 34.4651 29.4053 33.6971 28.68C32.9717 27.912 32.6091 26.888 32.6091 25.608V19.944H24.6091V25.608C24.6091 27.8693 25.0997 29.8747 26.0811 31.624C27.0624 33.3307 28.4064 34.6533 30.1131 35.592Z" fill="black"/><path d="M3.258 37V1.8H10.81V8.52H12.794L10.426 12.616C10.426 8.81867 11.2793 5.96 12.986 4.04C14.6927 2.12 17.21 1.16 20.538 1.16C24.3353 1.16 27.322 2.33334 29.498 4.68C31.674 7.02667 32.762 10.2693 32.762 14.408V16.968H24.378V14.984C24.378 12.7227 23.7807 10.9733 22.586 9.736C21.434 8.49867 19.834 7.88 17.786 7.88C15.6953 7.88 14.074 8.49867 12.922 9.736C11.8127 10.9733 11.258 12.7227 11.258 14.984V37H3.258Z" fill="black"/><path d="M3.742 48C7.492 44 11.242 44 14.992 48C18.742 52 22.492 52 26.242 48C29.992 44 33.742 44 37.492 48C41.242 52 44.992 52 48.742 48" stroke="#ff2056" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="43.742" cy="5" r="4" fill="#ff2056" /></svg>
</x-mail::header>
</x-slot:header>
# Verify your email

Hi {{ $name }},
<br><br>
Somebody used this email when registering on <a href="{{ $appUrl }}" style="color: #ff2056; text-decoration: underline;">{{ config('app.name') }}</a>.
<br><br>
If this was you, please confirm your email address to activate your account.

<x-mail::button :url="$verifyUrl">
Verify email
</x-mail::button>

This link expires in 24 hours. If you didn't request this, you can ignore this email.

Thanks,<br>
Redline
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} Redline with {{ config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
