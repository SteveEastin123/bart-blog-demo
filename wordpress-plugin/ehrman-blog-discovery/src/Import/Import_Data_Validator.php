<?php
/**
 * Authoritative import-data validation.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates source structure and cross-document relationships.
 *
 * @phpstan-type SourceRecord array<string,mixed>
 * @phpstan-type SourceLists array{posts:list<mixed>,topics:list<mixed>,categories:list<mixed>,subject_areas_1:list<mixed>,subject_areas_2:list<mixed>}
 * @phpstan-type ValidSourceData array{posts:list<SourceRecord>,topics:list<SourceRecord>,categories:list<SourceRecord>,subject_areas_1:list<SourceRecord>,subject_areas_2:list<SourceRecord>}
 */
final class Import_Data_Validator {
	/**
	 * Validates source structure and cross-file references.
	 *
	 * @param array<string,array<int,mixed>> $data Source datasets.
	 * @phpstan-param SourceLists $data
	 * @return list<string> Non-fatal validation warnings.
	 * @phpstan-assert ValidSourceData $data
	 * @throws RuntimeException When source validation fails.
	 */
	public function validate( array $data ): array {
		$errors         = array();
		$warnings       = array();
		$topic_names    = $this->named_record_map( $data['topics'], 'topic', $errors );
		$category_names = $this->named_record_map( $data['categories'], 'category', $errors );
		$this->assert_unique_slugs( array_keys( $topic_names ), 'topic', $errors );
		$this->assert_unique_slugs( array_keys( $category_names ), 'category', $errors );

		$category_orders = array();
		foreach ( $data['categories'] as $index => $category ) {
			if ( ! $this->is_record( $category ) ) {
				$errors[] = "Category record {$index} is not an object.";
				continue;
			}
			$name = $this->clean( $category['name'] ?? '' );
			$this->require_string_field( $category, 'description', "category {$name}", $errors );
			$order                    = $this->validate_string_list( $category['topicOrder'] ?? null, "category {$name} topicOrder", $errors );
			$category_orders[ $name ] = array_flip( $order );
			foreach ( $order as $topic_name ) {
				if ( ! isset( $topic_names[ $topic_name ] ) ) {
					$errors[] = "Category {$name} references unknown topic {$topic_name}.";
				}
			}
		}

		$canonical_topic_labels = array();
		foreach ( array_keys( $topic_names ) as $topic_name ) {
			$canonical_topic_labels[ $this->normalize( $topic_name ) ] = $topic_name;
		}
		$topic_alias_owners = array();
		foreach ( $data['topics'] as $index => $topic ) {
			if ( ! $this->is_record( $topic ) ) {
				$errors[] = "Topic record {$index} is not an object.";
				continue;
			}
			$name = $this->clean( $topic['name'] ?? '' );
			$this->require_string_field( $topic, 'description', "topic {$name}", $errors );
			if ( isset( $topic['displayInBrowser'] ) && ! is_bool( $topic['displayInBrowser'] ) ) {
				$errors[] = "Topic {$name} has a non-boolean displayInBrowser value.";
			}
			$aliases = $this->validate_string_list( $topic['aliases'] ?? array(), "topic {$name} aliases", $errors, true );
			foreach ( $aliases as $alias ) {
				$normalized_alias = $this->normalize( $alias );
				if ( '' === $normalized_alias || $this->length( $alias ) > 191 || $this->length( $normalized_alias ) > 191 ) {
					$errors[] = "Topic {$name} contains an invalid alias {$alias}.";
					continue;
				}
				if ( isset( $canonical_topic_labels[ $normalized_alias ] ) ) {
					$errors[] = "Topic {$name} alias {$alias} conflicts with canonical topic {$canonical_topic_labels[ $normalized_alias ]}.";
				}
				if ( isset( $topic_alias_owners[ $normalized_alias ] ) && $topic_alias_owners[ $normalized_alias ] !== $name ) {
					$errors[] = "Topic alias {$alias} is assigned to both {$topic_alias_owners[ $normalized_alias ]} and {$name}.";
				}
				$topic_alias_owners[ $normalized_alias ] = $name;
			}
			$categories = $this->validate_string_list( $topic['categories'] ?? null, "topic {$name} categories", $errors );
			if ( empty( $categories ) ) {
				$warnings[] = "Topic {$name} is not linked to a category.";
			}
			foreach ( $categories as $category_name ) {
				if ( ! isset( $category_names[ $category_name ] ) ) {
					$errors[] = "Topic {$name} references unknown category {$category_name}.";
					continue;
				}
				if ( ! isset( $category_orders[ $category_name ][ $name ] ) ) {
					$warnings[] = "Topic {$name} is linked to {$category_name} but is absent from that category's topicOrder.";
				}
			}
		}

		foreach ( array( 1, 2 ) as $path_number ) {
			$records       = $data[ "subject_areas_{$path_number}" ];
			$subject_names = array();
			$subject_slugs = array();
			foreach ( $records as $index => $subject_area ) {
				if ( ! $this->is_record( $subject_area ) ) {
					$errors[] = "Browse path {$path_number} subject-area record {$index} is not an object.";
					continue;
				}
				$name = $this->required_string( $subject_area['name'] ?? null, "Browse path {$path_number} subject-area name", $errors );
				if ( isset( $subject_names[ $name ] ) ) {
					$errors[] = "Browse path {$path_number} repeats subject area {$name}.";
				}
				$subject_names[ $name ] = true;
				$slug                   = $this->slugify( $name );
				if ( isset( $subject_slugs[ $slug ] ) ) {
					$errors[] = "Browse path {$path_number} has a subject-area slug collision for {$name}.";
				}
				$subject_slugs[ $slug ] = true;
				$this->require_string_field( $subject_area, 'description', "subject area {$name}", $errors );
				foreach ( $this->validate_string_list( $subject_area['categories'] ?? null, "subject area {$name} categories", $errors ) as $category_name ) {
					if ( ! isset( $category_names[ $category_name ] ) ) {
						$errors[] = "Subject area {$name} references unknown category {$category_name}.";
					}
				}
			}
		}

		$wp_ids = array();
		$urls   = array();
		foreach ( $data['posts'] as $index => $post ) {
			if ( ! $this->is_record( $post ) ) {
				$errors[] = "Post record {$index} is not an object.";
				continue;
			}
			$context   = 'post ' . ( $index + 1 );
			$wp_id     = $this->required_string( $post['wpId'] ?? null, "{$context} wpId", $errors );
			$title     = $this->required_string( $post['title'] ?? null, "{$context} title", $errors );
			$url       = $this->required_string( $post['url'] ?? null, "{$context} url", $errors );
			$date_text = $this->required_string( $post['dateText'] ?? null, "{$context} dateText", $errors );
			$author    = $this->required_string( $post['author'] ?? null, "{$context} author", $errors );
			$this->require_string_field( $post, 'description', "{$context} description", $errors );
			if ( array_key_exists( 'searchSummary', $post ) ) {
				if ( ! is_string( $post['searchSummary'] ) ) {
					$errors[] = "The searchSummary field for {$context} must be a string.";
				} elseif ( '' === trim( $post['searchSummary'] ) ) {
					$errors[] = "{$context} searchSummary cannot be empty when supplied.";
				} elseif ( str_contains( $post['searchSummary'], "\n" ) || str_contains( $post['searchSummary'], "\r" ) ) {
					$errors[] = "{$context} searchSummary cannot contain a line break.";
				} elseif ( $this->length( trim( $post['searchSummary'] ) ) > 1200 ) {
					$warnings[] = "{$context} searchSummary exceeds 1,200 characters.";
				}
			}

			if ( ! ctype_digit( $wp_id ) || (int) $wp_id < 1 ) {
				$errors[] = "{$context} has invalid wpId {$wp_id}.";
			} elseif ( isset( $wp_ids[ $wp_id ] ) ) {
				$errors[] = "Duplicate wpId {$wp_id}.";
			}
			$wp_ids[ $wp_id ] = true;

			if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$errors[] = "{$context} has an invalid URL.";
			} elseif ( isset( $urls[ $url ] ) ) {
				$errors[] = "Duplicate post URL {$url}.";
			}
			$urls[ $url ] = true;

			if ( $this->length( $author ) > 191 ) {
				$errors[] = "{$context} author exceeds 191 characters.";
			}
			if ( $this->length( $date_text ) > 64 || null === $this->parse_date( $date_text ) ) {
				$errors[] = "{$context} has an invalid publication date {$date_text}.";
			}

			$post_topics = $this->validate_string_list( $post['topics'] ?? null, "{$context} topics", $errors, true );
			foreach ( $post_topics as $topic_name ) {
				if ( ! isset( $topic_names[ $topic_name ] ) ) {
					$errors[] = "{$context} ({$title}) references unknown topic {$topic_name}.";
				}
			}

			$keywords = $this->validate_string_list( $post['secondaryKeywords'] ?? null, "{$context} secondaryKeywords", $errors, true );
			foreach ( $keywords as $keyword ) {
				$normalized = $this->normalize( $keyword );
				if ( '' === $normalized || $this->length( $keyword ) > 191 || $this->length( $normalized ) > 191 ) {
					$errors[] = "{$context} contains an invalid secondary keyword {$keyword}.";
				}
			}
		}

		if ( ! empty( $errors ) ) {
			$visible = array_slice( $errors, 0, 25 );
			$suffix  = count( $errors ) > 25 ? '\n- Additional validation errors: ' . ( count( $errors ) - 25 ) : '';
			throw new RuntimeException( "Import validation failed:\n- " . implode( "\n- ", $visible ) . $suffix );
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * Validates named records and indexes them by name.
	 *
	 * @param array<int,mixed>  $records Source records.
	 * @param string            $type    Singular record type.
	 * @param array<int,string> $errors  Validation errors collected by reference.
	 * @return array<string,array<string,mixed>> Records indexed by name.
	 */
	private function named_record_map( array $records, string $type, array &$errors ): array {
		$names = array();
		foreach ( $records as $index => $record ) {
			if ( ! $this->is_record( $record ) ) {
				$errors[] = ucfirst( $type ) . " record {$index} is not an object.";
				continue;
			}
			$name = $this->required_string( $record['name'] ?? null, ucfirst( $type ) . ' name', $errors );
			if ( isset( $names[ $name ] ) ) {
				$errors[] = "Duplicate {$type} name {$name}.";
			}
			$names[ $name ] = $record;
			if ( $this->length( $name ) > 191 || $this->length( $this->normalize( $name ) ) > 191 ) {
				$errors[] = ucfirst( $type ) . " name {$name} exceeds the supported length.";
			}
		}
		return $names;
	}

	/**
	 * Determines whether decoded JSON is an object-like record.
	 *
	 * @param mixed $value Candidate record.
	 * @phpstan-assert-if-true array<string,mixed> $value
	 */
	private function is_record( $value ): bool {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return false;
		}
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Ensures generated slugs are nonempty and unique.
	 *
	 * @param array<int,string> $names  Source names.
	 * @param string            $type   Singular record type.
	 * @param array<int,string> $errors Validation errors collected by reference.
	 */
	private function assert_unique_slugs( array $names, string $type, array &$errors ): void {
		$slugs = array();
		foreach ( $names as $name ) {
			$slug = $this->slugify( $name );
			if ( '' === $slug ) {
				$errors[] = ucfirst( $type ) . " {$name} produces an empty slug.";
			} elseif ( isset( $slugs[ $slug ] ) ) {
				$errors[] = ucfirst( $type ) . " slug collision between {$slugs[$slug]} and {$name}.";
			}
			$slugs[ $slug ] = $name;
		}
	}

	/**
	 * Validates a JSON list of unique, nonempty strings.
	 *
	 * @param mixed             $value              Candidate list.
	 * @param string            $context            Validation context.
	 * @param array<int,string> $errors             Validation errors collected by reference.
	 * @param bool              $use_normalized_key Compare using search normalization.
	 * @return array<int,string> Validated values.
	 */
	private function validate_string_list( $value, string $context, array &$errors, bool $use_normalized_key = false ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			$errors[] = "{$context} must be a list.";
			return array();
		}
		$values = array();
		$seen   = array();
		foreach ( $value as $index => $raw_value ) {
			if ( ! is_string( $raw_value ) || '' === trim( $raw_value ) ) {
				$errors[] = "{$context} contains an invalid value at position {$index}.";
				continue;
			}
			$item = trim( $raw_value );
			$key  = $use_normalized_key ? $this->normalize( $item ) : strtolower( $item );
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = "{$context} repeats {$item}.";
				continue;
			}
			$seen[ $key ] = true;
			$values[]     = $item;
		}
		return $values;
	}

	/**
	 * Validates and returns a required scalar string.
	 *
	 * @param mixed             $value   Candidate value.
	 * @param string            $context Validation context.
	 * @param array<int,string> $errors  Validation errors collected by reference.
	 */
	private function required_string( $value, string $context, array &$errors ): string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			$errors[] = "{$context} must be a string.";
			return '';
		}
		$clean = $this->clean( $value );
		if ( '' === $clean ) {
			$errors[] = "{$context} is required.";
		}
		return $clean;
	}

	/**
	 * Requires a record field to contain a string.
	 *
	 * @param array<string,mixed> $record  Source record.
	 * @param string              $field   Field name.
	 * @param string              $context Validation context.
	 * @param array<int,string>   $errors  Validation errors collected by reference.
	 */
	private function require_string_field( array $record, string $field, string $context, array &$errors ): void {
		if ( ! array_key_exists( $field, $record ) || ! is_string( $record[ $field ] ) ) {
			$errors[] = "The {$field} field for {$context} must be a string.";
		}
	}

	/**
	 * Clean a source value.
	 *
	 * @param mixed $value Value to clean.
	 */
	private function clean( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Normalize a source value for comparison.
	 *
	 * @param mixed $value Value to normalize.
	 */
	private function normalize( $value ): string {
		$text = strtolower( str_replace( '&', ' and ', $this->clean( $value ) ) );
		$text = trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
		return (string) preg_replace( '/\s+/', ' ', $text );
	}

	/**
	 * Convert a name to a source-data slug.
	 *
	 * @param string $name Name to slugify.
	 */
	private function slugify( string $name ): string {
		$normalized = $this->normalize( $name );
		return '' === $normalized ? 'item' : str_replace( ' ', '-', $normalized );
	}

	/**
	 * Parse a source date.
	 *
	 * @param string $text Source date text.
	 * @return array{display:string,published:string}|null Parsed date, or null.
	 */
	private function parse_date( string $text ): ?array {
		try {
			$date = new DateTimeImmutable( $text, new DateTimeZone( 'UTC' ) );
		} catch ( \Exception $error ) {
			return null;
		}
		$issues = DateTimeImmutable::getLastErrors();
		if ( is_array( $issues ) && ( $issues['warning_count'] > 0 || $issues['error_count'] > 0 ) ) {
			return null;
		}
		return array(
			'display'   => str_contains( $text, 'T' ) ? $date->format( 'F j, Y' ) : $text,
			'published' => $date->format( 'Y-m-d' ) . ' 00:00:00',
		);
	}

	/**
	 * Measure a source string.
	 *
	 * @param string $value Value to measure.
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
