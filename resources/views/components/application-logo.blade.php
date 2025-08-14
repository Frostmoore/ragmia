{{-- resources/views/components/application-logo.blade.php --}}
@props(['class' => ''])

<div {{ $attributes->merge(['class' => "inline-block $class"]) }} aria-label="Bolt Threads">
    {{-- Variante per tema chiaro --}}
    <img
        src="{{ asset('img/bt-monogram-light.png') }}"
        alt="Bolt Threads"
        class="block dark:hidden w-full h-full object-contain"
        width="160" height="160"
        loading="eager" fetchpriority="high"
    />

    {{-- Variante per tema scuro --}}
    <img
        src="{{ asset('img/bt-monogram-dark.png') }}"
        alt="Bolt Threads"
        class="hidden dark:block w-full h-full object-contain"
        width="160" height="160"
        loading="eager" fetchpriority="high"
    />
</div>
