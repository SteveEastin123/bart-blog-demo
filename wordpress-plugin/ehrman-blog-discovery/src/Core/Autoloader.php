<?php
/**
 * Runtime PSR-4 autoloader for the production plugin package.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads plugin classes from feature-oriented source directories. */
final class Autoloader {
	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * PSR-4 base directories for the plugin namespace.
	 *
	 * @var list<string>
	 */
	private const BASE_DIRECTORIES = array(
		'Core',
		'Browse',
		'KeywordSearch',
		'AskAI',
		'Analytics',
		'Ingestion',
		'Import',
		'Http',
		'Shared',
	);

	/** Registers the plugin class loader. */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Loads one class that belongs to the plugin namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	private static function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( self::PREFIX ) );
		if ( '' === $relative_class ) {
			return;
		}
		$relative_path = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		foreach ( self::BASE_DIRECTORIES as $directory ) {
			$file = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $relative_path;
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
