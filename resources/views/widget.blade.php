<!-- Redline -->
{{-- The widget is a Vue Custom Element (Shadow DOM). Only its transport URLs are
     baked into the HTML; capability flags (features, guest reporting, auth
     fields/registration, CSRF names) are fetched from GET /redline/api/bootstrap
     at mount. The stylesheet is compiled into the script and injected into the
     shadow root, so no separate CSS link is needed. --}}
<redline-widget
    id="redline-widget"
    api="{{ $url }}"
    dashboard="{{ $dashboard }}"
    theme="{{ request()->cookie('redline_theme', 'dark') }}"
    csrf="{{ $csrfCookie }}"
></redline-widget>
@if ($vite)
    <script type="module" src="{{ $vite }}/@@vite/client"></script>
    <script type="module" src="{{ $vite }}/src/widget.ts"></script>
@else
    <script src="{{ url('vendor/redline/redline.js') }}?v={{ $version }}"></script>
@endif
<!-- end Redline -->
