<?php
/**
 * Removes EXIF and other metadata (camera, GPS location, time taken) from
 * photos sellers upload, when the server has the Imagick PHP extension.
 * Without Imagick the photos are stored unchanged.
 */

defined( 'ABSPATH' ) || exit;

class HK_Images {

	const STRIPPABLE = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** Whether metadata can be removed on this server. */
	public static function can_strip(): bool {
		$can = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		/**
		 * Filters whether uploaded seller photos have their metadata removed.
		 * Return false to keep the metadata even when Imagick is available.
		 */
		return (bool) apply_filters( 'hk_strip_image_metadata', $can );
	}

	/**
	 * Remove metadata from an image file in place. The picture is first turned
	 * upright according to its EXIF orientation (which is removed too), and the
	 * colour profile is kept so the colours do not change.
	 *
	 * @return bool True when the file was rewritten.
	 */
	public static function strip_metadata( string $path, string $mime ): bool {
		if ( ! self::can_strip() || ! in_array( $mime, self::STRIPPABLE, true ) || ! is_file( $path ) ) {
			return false;
		}
		try {
			$im = new Imagick( $path );
			if ( $im->getNumberImages() > 1 ) {
				$im->clear();
				return false; // Animated images are left alone.
			}
			self::auto_orient( $im );
			$icc = null;
			try {
				$icc = $im->getImageProfile( 'icc' );
			} catch ( ImagickException $e ) {
				$icc = null;
			}
			$im->stripImage();
			if ( $icc ) {
				$im->profileImage( 'icc', $icc );
			}
			if ( 'image/jpeg' === $mime ) {
				// Keep the original quality where Imagick can read it.
				$q = (int) $im->getImageCompressionQuality();
				$im->setImageCompressionQuality( $q > 0 ? $q : 90 );
			}
			$ok = $im->writeImage( $path );
			$im->clear();
			return (bool) $ok;
		} catch ( Throwable $e ) {
			// A file Imagick cannot process is stored as it was.
			return false;
		}
	}

	private static function auto_orient( Imagick $im ): void {
		switch ( $im->getImageOrientation() ) {
			case Imagick::ORIENTATION_TOPRIGHT:
				$im->flopImage();
				break;
			case Imagick::ORIENTATION_BOTTOMRIGHT:
				$im->rotateImage( '#000', 180 );
				break;
			case Imagick::ORIENTATION_BOTTOMLEFT:
				$im->flipImage();
				break;
			case Imagick::ORIENTATION_LEFTTOP:
				$im->transposeImage();
				break;
			case Imagick::ORIENTATION_RIGHTTOP:
				$im->rotateImage( '#000', 90 );
				break;
			case Imagick::ORIENTATION_RIGHTBOTTOM:
				$im->transverseImage();
				break;
			case Imagick::ORIENTATION_LEFTBOTTOM:
				$im->rotateImage( '#000', 270 );
				break;
		}
		$im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
	}
}
