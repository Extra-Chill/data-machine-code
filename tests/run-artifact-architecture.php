<?php

declare(strict_types=1);

$forbidden = array(
	'DataMachine\\Core\\JobArtifacts',
	'DataMachine\\Core\\Database\\Jobs\\Jobs',
	'DataMachine\\Engine\\Bundle\\BundleSchema',
);

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(dirname(__DIR__) . '/inc', FilesystemIterator::SKIP_DOTS)
);

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$contents = file_get_contents($file->getPathname());
	if ( false === $contents ) {
		throw new RuntimeException('Unable to read ' . $file->getPathname());
	}

	foreach ( $forbidden as $internal_class ) {
		if ( str_contains($contents, $internal_class) ) {
			throw new RuntimeException(sprintf('Forbidden Data Machine internal class %s found in %s.', $internal_class, $file->getPathname()));
		}
	}
}

echo "run artifact architecture test passed.\n";
