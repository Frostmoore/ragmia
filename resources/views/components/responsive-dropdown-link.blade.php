@props(['href' => '#'])

<a href="{{ $href }}"
   {{ $attributes->merge([
        'class' =>
        'block ps-6 pe-4 py-2 text-base font-medium text-gray-600 dark:text-gray-300
         hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-100'
   ]) }}>
    {{ $slot }}
</a>
