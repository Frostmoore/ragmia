@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 text-start text-base font-medium
               bg-gray-100 border-indigo-500 text-gray-900
               dark:bg-gray-800 dark:text-gray-100'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 text-start text-base font-medium
               border-transparent text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300
               dark:text-gray-300 dark:hover:text-gray-100 dark:hover:bg-gray-800 dark:hover:border-gray-600';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
