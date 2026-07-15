<?php
/**
 * Encryption helper for reversible integration secrets.
 *
 * @package YoBooking
 */

namespace YoBooking\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts webhook signing secrets with WordPress installation salts.
 */
final class SecretVault {
	/** @param string $plaintext Secret. @return string */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext || ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'secure_auth' ), true );
		$iv  = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $ciphertext ? '' : base64_encode( $iv . $tag . $ciphertext );
	}

	/** @param string $encrypted Encrypted secret. @return string */
	public static function decrypt( $encrypted ) {
		if ( ! $encrypted || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$decoded = base64_decode( (string) $encrypted, true );
		if ( false === $decoded || strlen( $decoded ) < 29 ) {
			return '';
		}
		$iv = substr( $decoded, 0, 12 );
		$tag = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );
		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', hash( 'sha256', wp_salt( 'secure_auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plaintext ? '' : $plaintext;
	}
}
