<?php
declare(strict_types=1);

namespace WMCyber\DORA\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DORA\Register\RegisterRepository;
use WMCyber\DORA\Register\Validator;

/**
 * WordPress 6.5+ Block Bindings (`wm-dora/metrics`) and GenerateBlocks 2.x Dynamic Tag (`{{wm_dora_metric}}`)
 * exposing computed register metrics without fixed status claims.
 */
final class EcosystemBridge {
	public const BINDING_SOURCE = 'wm-dora/metrics';
	public const DYNAMIC_TAG    = 'wm_dora_metric';

	public function __construct( private readonly RegisterRepository $repository ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'register_block_bindings' ] );
		add_action( 'init', [ $this, 'register_generateblocks_tag' ] );
		add_filter( 'generateblocks_dynamic_tag_replacement', [ $this, 'filter_generateblocks_replacement' ], 10, 2 );
	}

	public function register_block_bindings(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			'wm-dora/metrics',
			[
				'label'              => __( 'DORA IKT-Register Kennzahlen', 'wm-dora-ict-register' ),
				'get_value_callback' => [ $this, 'resolve_block_binding' ],
				'uses_context'       => [],
			]
		);
	}

	public function register_generateblocks_tag(): void {
		if ( ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			return;
		}

		new \GenerateBlocks_Register_Dynamic_Tag(
			[
				'title'       => __( 'DORA IKT-Register Kennzahl', 'wm-dora-ict-register' ),
				'tag'         => self::DYNAMIC_TAG,
				'type'        => 'wm-dora',
				'supports'    => [],
				'description' => __( 'Berechnete Kennzahl aus dem DORA-IKT-Register (suppliers, contracts, cif_contracts, subcontractors, orphan_contracts, competent_authority, health_status).', 'wm-dora-ict-register' ),
				'return'      => [ $this, 'render_dynamic_tag' ],
			]
		);
	}

	/**
	 * @param mixed $replacement Current replacement value.
	 * @param mixed $options     Tag descriptor array.
	 */
	public function filter_generateblocks_replacement( mixed $replacement, mixed $options = [] ): mixed {
		if ( ! is_array( $options ) || ( '' !== $replacement && null !== $replacement ) || self::DYNAMIC_TAG !== ( $options['tag'] ?? '' ) ) {
			return $replacement;
		}
		$tag_options = isset( $options['options'] ) && is_array( $options['options'] ) ? $options['options'] : $options;
		return $this->render_dynamic_tag( $tag_options );
	}

	/**
	 * @param array<string, mixed> $source_args
	 */
	public function resolve_block_binding( array $source_args, mixed $block_instance = null, string $attribute_name = 'content' ): ?string {
		$key = sanitize_key( (string) ( $source_args['key'] ?? 'contracts' ) );
		return $this->metric_value( $key );
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $block
	 */
	public function render_dynamic_tag( array $options = [], array $block = [], mixed $instance = null ): string {
		$key = sanitize_key( (string) ( $options['key'] ?? 'contracts' ) );
		return (string) ( $this->metric_value( $key ) ?? '' );
	}

	public function metric_value( string $key ): ?string {
		$counts   = $this->repository->counts();
		$settings = (array) get_option( 'wm_dora_settings', [] );

		return match ( $key ) {
			'suppliers'           => (string) $counts['suppliers'],
			'contracts'           => (string) $counts['contracts'],
			'cif_contracts'       => (string) $counts['cif_contracts'],
			'subcontractors'      => (string) $counts['subcontractors'],
			'orphan_contracts'    => (string) $counts['orphan_contracts'],
			'competent_authority' => (string) ( $settings['competent_authority'] ?? '' ),
			'health_status'       => $this->compute_health( $counts, $settings ),
			default               => null,
		};
	}

	/**
	 * @param array{suppliers: int, contracts: int, cif_contracts: int, subcontractors: int, orphan_contracts: int} $counts
	 * @param array<string, mixed>                                                                                   $settings
	 */
	private function compute_health( array $counts, array $settings ): string {
		if ( ! $this->repository->tables_exist() ) {
			return 'ERROR';
		}
		if ( null === Validator::lei( $settings['entity_lei'] ?? null ) ) {
			return 'NEEDS_CONFIG';
		}
		return $counts['orphan_contracts'] > 0 ? 'DEGRADED' : 'HEALTHY';
	}
}
