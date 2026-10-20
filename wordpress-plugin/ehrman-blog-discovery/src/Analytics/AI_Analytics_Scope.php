<?php
/**
 * Scope shared by the active Ask AI reports and reset operation.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps retired methods out of active reports without deleting their history. */
final class AI_Analytics_Scope {
	/**
	 * Returns the fixed SQL predicate for a request table aliased as r.
	 *
	 * @return literal-string
	 */
	public static function where(): string {
		// The old two-vector experiment used the same request type as Ask AI 2.
		return "r.request_type='semantic' AND r.prompt_version<>'hybrid-metadata-1'";
	}
}
