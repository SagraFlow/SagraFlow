<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Cassa' }} - {{ config('app.name', 'SagraFlow') }}</title>
    <script>
        if (localStorage.theme === 'dark' || (! ('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- overscroll-none: the till fills the viewport and never scrolls as a page,
     so any vertical drag that reaches the document is a stray one. Refusing it
     here keeps the interface still under the finger. --}}
<body class="h-full overscroll-none bg-neutral-100 text-neutral-900 antialiased dark:bg-neutral-950 dark:text-neutral-100">
    {{ $slot }}
</body>
</html>
