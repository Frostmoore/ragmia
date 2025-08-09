@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium leading-5
               border-indigo-500 text-gray-900 dark:text-gray-100
               focus:outline-none focus:border-indigo-700 transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5
               text-gray-500 hover:text-gray-700 hover:border-gray-300
               dark:text-gray-300 dark:hover:text-gray-100 dark:hover:border-gray-600
               focus:outline-none focus:border-gray-300 dark:focus:border-gray-600 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
