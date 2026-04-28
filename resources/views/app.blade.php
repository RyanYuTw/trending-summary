<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Trending Summary</title>
    @if(app()->environment('local'))
        @vite(['frontend/src/main.ts'], 'vendor/trending-summary')
    @else
        @php
            $manifestPath = public_path('vendor/trending-summary/manifest.json');
            $manifest = file_exists($manifestPath)
                ? json_decode(file_get_contents($manifestPath), true)
                : [];
        @endphp
        @if(!empty($manifest['frontend/src/main.ts']['css'][0]))
            <link rel="stylesheet" href="{{ asset('vendor/trending-summary/' . $manifest['frontend/src/main.ts']['css'][0]) }}">
        @endif
        @if(!empty($manifest['frontend/src/main.ts']['file']))
            <script type="module" src="{{ asset('vendor/trending-summary/' . $manifest['frontend/src/main.ts']['file']) }}"></script>
        @endif
    @endif
</head>
<body>
    <div id="app"></div>
</body>
</html>
