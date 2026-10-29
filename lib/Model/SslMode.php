<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Model;

/**
 * Transport security used for the connection to the IMAP server
 */
enum SslMode: string {
	/** Plain IMAP, credentials are sent unencrypted */
	case None = 'none';
	/** Implicit TLS (IMAPS, usually port 993) */
	case Ssl = 'ssl';
	/** Plain connection upgraded via STARTTLS (usually port 143) */
	case StartTls = 'tls';

	public function defaultPort(): int {
		return $this === self::Ssl ? 993 : 143;
	}

	/**
	 * URL scheme understood by curl for this mode
	 */
	public function urlScheme(): string {
		return $this === self::Ssl ? 'imaps' : 'imap';
	}
}
