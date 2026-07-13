<?php
/**
 * Simple .env file loader (no external dependencies)
 */
class EnvLoader
{
    private static $loaded = false;

    /**
     * Load environment variables from .env file
     */
    public static function load(string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        $path = $path ?? __DIR__ . '/../.env';
        if (!file_exists($path)) {
            // Try .env.example as fallback
            $path = __DIR__ . '/../.env.example';
            if (!file_exists($path)) {
                return;
            }
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // Remove surrounding quotes if present
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $value = self::resolveVariables($value);

            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        self::$loaded = true;
    }

    /**
     * Get an environment variable
     */
    public static function get(string $key, $default = null)
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Resolve ${VAR} patterns in values
     */
    private static function resolveVariables(string $value): string
    {
        return preg_replace_callback('/\$\{([^}]+)\}/', function ($matches) {
            return self::get($matches[1], '');
        }, $value);
    }
}
