<?php

/*
 * PHPStan bootstrap: subset of Laravel framework (illuminate/foundation) global
 * helpers used by this package. Foundation is not a direct dependency (the
 * helpers exist at runtime inside any Laravel application), so they are
 * declared here for static analysis only. Signatures mirror
 * vendor/laravel/framework/src/Illuminate/Foundation/helpers.php.
 */

namespace {
    if (!function_exists('app')) {
        /**
         * @template TClass of object
         * @param class-string<TClass>|null $abstract
         * @return ($abstract is null ? \Illuminate\Contracts\Foundation\Application : TClass)
         */
        function app(?string $abstract = null, array $parameters = [])
        {
        }
    }

    if (!function_exists('config')) {
        /**
         * @param array<string, mixed>|string|null $key
         * @return ($key is string ? mixed : array<string, mixed>)
         */
        function config(array|string|null $key = null, mixed $default = null)
        {
        }
    }

    if (!function_exists('base_path')) {
        function base_path(string $path = ''): string
        {
        }
    }

    if (!function_exists('config_path')) {
        function config_path(string $path = ''): string
        {
        }
    }

    if (!function_exists('response')) {
        /**
         * @param mixed $content
         * @return ($content is null ? \Illuminate\Routing\ResponseFactory : \Illuminate\Http\Response)
         */
        function response($content = null, int $status = 200, array $headers = [])
        {
        }
    }
}
