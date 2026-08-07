<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redline Dashboard</title>
    @if ($vite)
        <script type="module" src="{{ $vite }}/@@vite/client"></script>
        <link rel="stylesheet" href="{{ $vite }}/css/redline-dashboard.css">
        <script type="module" src="{{ $vite }}/src/dashboard.ts"></script>
    @else
        <link rel="stylesheet" href="{{ url('vendor/redline/redline-dashboard.css') }}?v={{ $version }}">
        <script defer src="{{ url('vendor/redline/redline-dashboard.js') }}?v={{ $version }}"></script>
    @endif
</head>
<body>
    <redline-dashboard
        id="redline-dashboard"
        api="{{ $url }}"
        dashboard="{{ $base }}"
        theme="{{ request()->cookie('redline_theme', 'dark') }}"
        csrf="{{ $csrfCookie }}"
    ></redline-dashboard>
</body>
</html>
