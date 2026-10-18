<?php
/** Frozen fresh-question comparison; no production configuration or index writes. */
namespace EhrmanSectionPilot;

final class End_To_End_Run {
	public const RUN = 'end-to-end-300-2026-10-17';

	public static function question( int $number ): array {
		$directory = Index::DIRECTORY . '/' . self::RUN;
		$manifest  = json_decode( file_get_contents( $directory . '/manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		$file      = $directory . '/questions.json';
		if ( hash_file( 'sha256', $file ) !== $manifest['fixtureQuestionsHash'] || hash_file( 'sha256', Index::DIRECTORY . '/index.json' ) !== $manifest['indexHash'] ) {
			throw new \RuntimeException( 'Frozen experiment data changed.' );
		}
		foreach ( $manifest['pilotSources'] as $name => $hash ) {
			if ( hash_file( 'sha256', __DIR__ . '/' . $name ) !== $hash ) {
				throw new \RuntimeException( 'Experiment implementation changed after freezing.' );
			}
		}
		$questions = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		if ( $number < 1 || $number > 50 || 50 !== count( $questions['questions'] ) ) {
			throw new \InvalidArgumentException( 'Unknown frozen question.' );
		}
		return $questions['questions'][ $number - 1 ];
	}

	public function run( int $number, string $arm ): array {
		if ( ! in_array( $arm, array( 'ask-ai-2', 'sections-300' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown comparison arm.' );
		}
		$question = self::question( $number );
		$sections = 'sections-300' === $arm;
		$model    = (string) getenv( 'EHRMAN_DISCOVERY_AI_MODEL' );
		if ( '' !== $model && 'gpt-5.4-mini' !== $model ) {
			throw new \RuntimeException( 'The frozen reviewer model changed.' );
		}
		$result = ( new Service() )->search( $question['question'], $sections ? '300' : 'baseline', $sections, true );
		return $result + array(
			'run'        => self::RUN,
			'questionId' => $number,
			'arm'        => $arm,
			'group'      => $question['group'],
		);
	}
}
