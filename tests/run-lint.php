<?php
/**
 * Syntax-lint every PHP file in the project.
 *
 * Replaces the bash `while read … php -l` loop that used to live in the CI
 * workflow: that only ran on Linux, so nobody could reproduce a lint failure
 * locally on Windows. PHP_BINARY keeps this portable and guarantees the same
 * interpreter that runs the suite is the one doing the checking.
 *
 * Run with:  php tests/run-lint.php
 *
 * @package SmartLogin
 */

$root     = dirname( __DIR__ );
$skip_dir = array( '.git', 'vendor', 'node_modules', 'build', 'dist' );

$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( $current ) use ( $skip_dir ): bool {
			return ! ( $current->isDir() && in_array( $current->getBasename(), $skip_dir, true ) );
		}
	)
);

$checked  = 0;
$failures = array();

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	++$checked;

	$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file->getPathname() );
	$output  = array();
	$status  = 0;

	exec( $command . ' 2>&1', $output, $status );

	if ( 0 !== $status ) {
		$relative              = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		$failures[ $relative ] = implode( "\n", $output );
	}
}

foreach ( $failures as $relative => $message ) {
	printf( "  FAIL  %s\n%s\n", $relative, $message );
}

printf( "\nSyntax lint: %d files checked, %d failed\n", $checked, count( $failures ) );

exit( $failures ? 1 : 0 );
