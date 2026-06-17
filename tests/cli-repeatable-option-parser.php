<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Cli/CliRepeatableOptionParser.php';

use DataMachineCode\Cli\CliRepeatableOptionParser;

function assert_same( array $expected, array $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			sprintf(
				"%s\nExpected: %s\nActual:   %s",
				$message,
				json_encode($expected),
				json_encode($actual)
			)
		);
	}
}

assert_same(
	array( 'one.php', 'two.php', 'three.php' ),
	CliRepeatableOptionParser::collect(
		'rel',
		array( 'wp', 'datamachine-code', 'workspace', 'git', 'add', 'repo', '--rel=one.php', '--rel', 'two.php', '--other=value', '--rel=three.php' )
	),
	'Collects both assignment and separated repeatable option forms in order.'
);

assert_same(
	array( 'kept' ),
	CliRepeatableOptionParser::collect('rel', array( '--rel=', '--rel', '--next=value', '--rel', '', '--rel', 'kept' )),
	'Ignores empty values and bare flags without a following value.'
);

$GLOBALS['argv'] = array( 'wp', 'cmd', '--drop-path', 'a.txt', '--drop-path=b.txt' );
assert_same(
	array( 'a.txt', 'b.txt' ),
	CliRepeatableOptionParser::collect('drop-path'),
	'Defaults to global argv when no argv is provided.'
);

echo "cli-repeatable-option-parser: ok\n";
