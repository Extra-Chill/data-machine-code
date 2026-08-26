<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', dirname(__DIR__) . '/fixtures/');
}

if ( ! function_exists('wp_json_encode') ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode($value, $flags, $depth);
	}
}

function dmc_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function dmc_test_source( string $relative_path ): string {
	$source = file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($relative_path, '/'));
	if ( false === $source ) {
		throw new RuntimeException('Unable to read test source: ' . $relative_path);
	}
	return $source;
}
