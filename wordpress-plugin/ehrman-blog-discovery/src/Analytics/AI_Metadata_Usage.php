<?php
/**
 * Durable metadata-generation cost history.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps paid attempts independent of editable or deletable ingestion drafts. */
final class AI_Metadata_Usage {
	/**
	 * Whether the existing ingestion rate schedule covers this model.
	 *
	 * @param string $model API model identifier.
	 */
	public static function has_pricing( string $model ): bool {
		return str_starts_with( $model, 'gpt-5.6-sol' ) || 'gpt-5.6' === $model;
	}

	/**
	 * Captures a generation run's identifiers without retaining full post text.
	 *
	 * @param array<string,mixed> $draft Ingestion draft.
	 * @return array<string,mixed> Usage context.
	 */
	public static function context( array $draft ): array {
		$run = Database::text( $draft['usage_run_id'] ?? '' );
		return array(
			'run_id'     => '' !== $run ? $run : 'legacy-' . md5( Database::text( $draft['id'] ?? '' ) . ':' . Database::text( $draft['created_at'] ?? '' ) ),
			'post_id'    => Database::integer( $draft['source_wp_id'] ?? 0 ),
			'post_title' => Database::text( $draft['title'] ?? '' ),
		);
	}

	/**
	 * Appends an attempt, including failed output validation and unknown charges.
	 *
	 * @param array<string,int|float|string> $metrics   Reported metrics.
	 * @param array<string,mixed>            $context   Generation context.
	 * @param string                         $purpose   Metadata or post embedding.
	 * @param bool                           $succeeded Whether usable output was returned.
	 * @param string                         $error     Stable local error code.
	 * @param bool                           $known     Whether usage and pricing are known.
	 * @return bool Whether storage succeeded.
	 */
	public static function record( array $metrics, array $context, string $purpose, bool $succeeded, string $error, bool $known ): bool {
		if ( empty( $context['run_id'] ) ) {
			return true;
		}
		$context['purpose']         = $purpose;
		$context['cost_known']      = $known ? 1 : 0;
		$context['pricing_version'] = 'post_embedding' === $purpose ? 'embedding-small-2026-08-21' : 'ingestion-gpt-5.6-v1';
		if ( ! empty( $context['history_import'] ) ) {
			$context['pricing_version'] = 'legacy-recorded-estimate';
		} elseif ( ! $known ) {
			$context['pricing_version'] = 'usage-or-pricing-unavailable';
		}
		$metrics['total_tokens'] = Database::integer( $metrics['input_tokens'] ?? 0 ) + Database::integer( $metrics['output_tokens'] ?? 0 );
		return AI_Usage::insert( $metrics, false, $succeeded, $error, '', $context );
	}

	/**
	 * Preserves the surviving legacy draft totals once, without inventing prior attempts.
	 *
	 * @throws \RuntimeException When a history snapshot cannot be stored.
	 */
	public static function import_history(): void {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$drafts = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE usage_run_id=%s', $tables['ingestion_drafts'], '' ), ARRAY_A ) );
		foreach ( $drafts as $draft ) {
			$context                   = self::context( $draft );
			$context['history_import'] = 1;
			$context['created_at']     = Database::text( $draft['analysis_started_at'] ?? $draft['updated_at'] );
			foreach ( array( 'metadata_generation', 'post_embedding' ) as $purpose ) {
				$embedding       = 'post_embedding' === $purpose;
				$prefix          = $embedding ? 'embedding_' : '';
				$input           = Database::integer( $draft[ $prefix . 'input_tokens' ] ?? 0 );
				$output          = $embedding ? 0 : Database::integer( $draft['output_tokens'] ?? 0 );
				$cost            = (float) Database::text( $draft[ $prefix . 'estimated_cost_usd' ] ?? 0 );
				$unused_vector   = $embedding && in_array( $draft['embedding_status'], array( 'not_requested', 'not_applicable' ), true ) && 0 === $input && 0.0 === $cost;
				$unused_analysis = ! $embedding && 0 === Database::integer( $draft['analysis_attempt'] ?? 0 ) && 0 === $input + $output && 0.0 === $cost;
				if ( $unused_vector || $unused_analysis ) {
					continue;
				}
				$context['event_key'] = 'history-' . hash( 'sha256', Database::text( $context['run_id'] ) . ':' . $purpose );
				$exists               = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE event_key=%s', $tables['ai_usage'], $context['event_key'] ) );
				if ( null !== $exists ) {
					continue;
				}
				$metrics = array(
					'model'               => Database::text( $draft[ $prefix . 'model' ] ?? '' ),
					'response_id'         => Database::text( $draft[ $prefix . 'response_id' ] ?? '' ),
					'input_tokens'        => $input,
					'output_tokens'       => $output,
					'cached_input_tokens' => $embedding ? 0 : Database::integer( $draft['cached_input_tokens'] ?? 0 ),
					'reasoning_tokens'    => $embedding ? 0 : Database::integer( $draft['reasoning_tokens'] ?? 0 ),
					'estimated_cost_usd'  => $cost,
				);
				$success = $embedding ? 'complete' === $draft['embedding_status'] : in_array( $draft['status'], array( 'ready', 'held', 'approved' ), true );
				$known   = $cost > 0 || ( $input + $output > 0 && ( $embedding ? 'text-embedding-3-small' === $metrics['model'] : self::has_pricing( $metrics['model'] ) ) );
				if ( ! self::record( $metrics, $context, $purpose, $success, 'historical_snapshot', $known ) ) {
					throw new \RuntimeException( 'Could not preserve historical metadata usage.' );
				}
			}
		}
		add_option( 'ehrman_discovery_cost_tracking_started', current_time( 'mysql', true ), '', false );
	}
}
