<x-guest-layout>
    <div class="space-y-6">
        <div class="text-center space-y-2">
            <h1 class="text-2xl font-semibold">Benvenuto in {{ config('app.name', 'Laravel') }}</h1>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Accedi o registrati per usare la chat con progetti e cartelle. Tema chiaro/scuro adattivo.
            </p>
        </div>

        <div class="flex flex-col gap-3">
            @if (Route::has('login'))
                <a href="{{ route('login') }}"
                   class="inline-flex w-full items-center justify-center rounded-lg px-4 py-2
                          bg-gray-900 text-white hover:bg-gray-800
                          dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-gray-200">
                    Accedi
                </a>
            @endif

            @if (Route::has('register'))
                <a href="{{ route('register') }}"
                   class="inline-flex w-full items-center justify-center rounded-lg px-4 py-2
                          bg-white text-gray-900 border border-gray-300 hover:bg-gray-50
                          dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600 dark:hover:bg-gray-700">
                    Registrati
                </a>
            @endif
        </div>

        <div class="text-center text-xs text-gray-500 dark:text-gray-400">
            Hai già effettuato l’accesso? Vai alla <a href="{{ url('/') }}" class="underline">home</a>.
        </div>
    </div>
</x-guest-layout>
