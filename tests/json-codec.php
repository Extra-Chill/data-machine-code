<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Support/JsonCodec.php';

use DataMachineCode\Support\JsonCodec;

function assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			sprintf(
				"%s\nExpected: %s\nActual: %s",
				$message,
				var_export($expected, true),
				var_export($actual, true)
			)
		);
	}
}

assert_same(
	'{"path":"foo/bar"}',
	JsonCodec::encode_or_default(array( 'path' => 'foo/bar' ), '{}', JSON_UNESCAPED_SLASHES),
	'Encodes with caller flags.'
);
assert_same(array( 'ok' => true ), JsonCodec::decode_array('{"ok":true}', array()), 'Decodes JSON arrays.');
assert_same(array(), JsonCodec::decode_array('not-json', array()), 'Defaults invalid JSON to an empty array.');
assert_same(null, JsonCodec::decode_array('not-json', null), 'Preserves nullable defaults.');

print "json-codec OK\n";
