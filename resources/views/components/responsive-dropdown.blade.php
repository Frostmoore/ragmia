@props(['align' => 'left'])

@php
    $alignmentClasses = match($align) {
        'right' => 'ms-auto',
        default => '',
    };
@endphp

<div x-data="{ open: false }" class="px-4">
    <button @click="open = !open"
            class="w-full flex items-center justify-between py-2">
        <div class="text-gray-700 dark:text-gray-300">
            {{ $trigger }}
        </div>
        <svg class="h-4 w-4 text-gray-500 dark:text-gray-400 transition-transform"
             :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
        </svg>
    </button>

    <div x-show="open" x-collapse class="{{ $alignmentClasses }} mt-1 space-y-1">
        {{ $content }}
    </div>
</div>
