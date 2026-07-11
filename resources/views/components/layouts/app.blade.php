<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Tower') }} — Tower</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100 font-sans antialiased">
        @isset($header)
            <header class="border-b border-slate-800 bg-slate-900/70 backdrop-blur">
                <div class="mx-auto max-w-7xl px-4 py-3 sm:px-6 lg:px-8 flex items-center justify-between">
                    <div class="flex items-baseline gap-3">
                        <a href="{{ $public ?? true ? url('/') : url('/board') }}" class="text-lg font-semibold tracking-tight">
                            Tower
                        </a>
                        <span class="text-xs text-slate-400">
                            {{ $subtitle ?? 'mission control' }}
                        </span>
                    </div>
                    <nav class="flex items-center gap-3 text-sm">
                        {{ $header ?? '' }}
                    </nav>
                </div>
            </header>
        @endisset

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </body>
</html>
