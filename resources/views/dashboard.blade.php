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
    >
    <!-- Static startup loader. Mirrors the markup created by
         redline-app/src/core/dashboard-loader.ts (showDashboardLoader finds
         this by id and reuses it, so no duplication when JS runs). Keep this
         in sync with that file - same id, classes, attributes and text. -->
        <style>
            .redline-loader-sweep {
                stroke-dasharray: 60;
                stroke-dashoffset: 60;
                animation: redline-loader-sweep 1.4s ease-in-out infinite;
            }

            @keyframes redline-loader-sweep {
                from { stroke-dashoffset: 60; }
                to   { stroke-dashoffset: -60; }
            }

            @media (prefers-reduced-motion: reduce) {
                .redline-loader-sweep {
                    animation: none;
                    stroke-dashoffset: 0;
                }
            }
        </style>
        <div id="redline-dashboard-loader" role="status" aria-live="polite">
            <div style="position: fixed; inset: 0; display:flex; flex-direction: column; justify-content: center; align-items: center; gap: 1rem; font-family: inherit;">
                <p style="color: #90a1b9;">Redline dashboard is loading</p>
                <svg viewBox="2.742 44 47 8" fill="none" aria-hidden="true" width="200">
                    <path d="M3.742 48C7.492 44 11.242 44 14.992 48C18.742 52 22.492 52 26.242 48C29.992 44 33.742 44 37.492 48C41.242 52 44.992 52 48.742 48" stroke="#90a1b9" style="opacity: 40%;" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                    <path d="M3.742 48C7.492 44 11.242 44 14.992 48C18.742 52 22.492 52 26.242 48C29.992 44 33.742 44 37.492 48C41.242 52 44.992 52 48.742 48" stroke="#ff2056" class="redline-loader-sweep" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
            </div>
        </div>
    </redline-dashboard>
</body>
</html>
