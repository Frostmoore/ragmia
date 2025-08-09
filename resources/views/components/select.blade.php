@props(['class' => ''])

<select {{ $attributes->merge([
    'class' => "rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100
                focus:ring-0 focus:border-gray-400 dark:focus:border-gray-500 $class"
]) }}>
    {{ $slot }}
</select>
