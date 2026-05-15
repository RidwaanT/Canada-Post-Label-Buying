<?php
/**
 * Purpose: Normalizes Canada Post label PDFs for 4x6 thermal output.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts Canada Post public PDFs to thermal label pages where possible.
 */
final class WLP_Label_Normalizer {
	private const THERMAL_WIDTH_MM  = 101.6;
	private const THERMAL_HEIGHT_MM = 152.4;
	private const PUBLIC_WIDTH_MM   = 279.4;
	private const PUBLIC_HEIGHT_MM  = 215.9;
	private const LABEL_LEFT_MM     = 159.46;
	private const LABEL_TOP_MM      = 25.4;
	private const LABEL_WIDTH_MM    = 112.89;
	private const TOLERANCE_MM      = 1.0;

	/**
	 * Normalizes a Canada Post PDF body, returning the original on failure.
	 *
	 * @param string $body PDF bytes.
	 */
	public static function normalize_pdf( string $body ): string {
		if ( ! class_exists( Fpdi::class ) || ! class_exists( StreamReader::class ) ) {
			return $body;
		}

		try {
			$pdf = new Fpdi( 'P', 'mm', array( self::THERMAL_WIDTH_MM, self::THERMAL_HEIGHT_MM ) );
			$pdf->SetMargins( 0, 0, 0 );
			$pdf->SetAutoPageBreak( false, 0 );
			$pdf->setSourceFile( StreamReader::createByString( $body ) );
			$template = $pdf->importPage( 1 );
			$size     = $pdf->getTemplateSize( $template );

			$width  = (float) ( $size['width'] ?? 0 );
			$height = (float) ( $size['height'] ?? 0 );

			if ( self::approx( $width, self::THERMAL_WIDTH_MM ) && self::approx( $height, self::THERMAL_HEIGHT_MM ) ) {
				return $body;
			}

			if ( ! self::approx( $width, self::PUBLIC_WIDTH_MM ) || ! self::approx( $height, self::PUBLIC_HEIGHT_MM ) ) {
				return $body;
			}

			$scale = self::THERMAL_WIDTH_MM / self::LABEL_WIDTH_MM;
			$pdf->AddPage( 'P', array( self::THERMAL_WIDTH_MM, self::THERMAL_HEIGHT_MM ) );
			$pdf->useTemplate(
				$template,
				-1 * self::LABEL_LEFT_MM * $scale,
				-1 * self::LABEL_TOP_MM * $scale,
				$width * $scale,
				$height * $scale
			);

			return $pdf->Output( 'S' );
		} catch ( Throwable $error ) {
			return $body;
		}
	}

	/**
	 * Merges PDF bodies into a single PDF.
	 *
	 * @param array<int, string> $bodies PDF byte strings.
	 */
	public static function merge_pdfs( array $bodies ): string {
		if ( ! class_exists( Fpdi::class ) || ! class_exists( StreamReader::class ) ) {
			throw new RuntimeException( esc_html__( 'PDF merging is unavailable because FPDI is not loaded.', 'woo-logistics-plugin' ) );
		}

		$output = new Fpdi();
		$output->SetMargins( 0, 0, 0 );
		$output->SetAutoPageBreak( false, 0 );

		foreach ( $bodies as $body ) {
			$source     = StreamReader::createByString( $body );
			$page_count = $output->setSourceFile( $source );

			for ( $page = 1; $page <= $page_count; $page++ ) {
				$template = $output->importPage( $page );
				$size     = $output->getTemplateSize( $template );
				$width    = (float) ( $size['width'] ?? self::THERMAL_WIDTH_MM );
				$height   = (float) ( $size['height'] ?? self::THERMAL_HEIGHT_MM );

				$output->AddPage( $width > $height ? 'L' : 'P', array( $width, $height ) );
				$output->useTemplate( $template, 0, 0, $width, $height );
			}
		}

		return $output->Output( 'S' );
	}

	/**
	 * Returns true when two millimeter values are effectively equal.
	 */
	private static function approx( float $a, float $b ): bool {
		return abs( $a - $b ) <= self::TOLERANCE_MM;
	}
}
