<!doctype html>
<html lang="hy">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="lager-realtime-key" content="{{ config('broadcasting.default') === 'reverb' ? config('broadcasting.connections.reverb.key') : '' }}">
    <title>Դիագեն Պլյուս · Պահեստ</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
