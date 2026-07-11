<x-layouts.app :title="'Sign in'" :subtitle="'admin only'">
    <x-slot:header>
        <a href="{{ url('/') }}" class="text-slate-300 hover:text-white">
            public board
        </a>
    </x-slot:header>

    <div class="mx-auto max-w-sm">
        <h1 class="mb-6 text-2xl font-semibold">Sign in</h1>

        @if ($errors->any())
            <div class="mb-4 rounded border border-amber-700 bg-amber-950/60 px-3 py-2 text-sm text-amber-200">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-slate-300">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="username"
                    required
                    autofocus
                    value="{{ old('email') }}"
                    class="mt-1 block w-full rounded border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 focus:border-sky-500 focus:outline-none"
                />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-300">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="mt-1 block w-full rounded border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 focus:border-sky-500 focus:outline-none"
                />
            </div>

            <div class="flex items-center">
                <input
                    id="remember"
                    name="remember"
                    type="checkbox"
                    value="1"
                    class="h-4 w-4 rounded border-slate-700 bg-slate-900 text-sky-500 focus:ring-sky-500"
                />
                <label for="remember" class="ml-2 text-sm text-slate-300">Remember me</label>
            </div>

            <button
                type="submit"
                class="w-full rounded bg-sky-600 px-4 py-2 font-medium text-white hover:bg-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-400"
            >
                Sign in
            </button>
        </form>
    </div>
</x-layouts.app>
