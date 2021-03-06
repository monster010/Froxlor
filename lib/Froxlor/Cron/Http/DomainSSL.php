<?php
namespace Froxlor\Cron\Http;

use Froxlor\Database\Database;
use Froxlor\Settings;

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Michael Kaufmann <mkaufmann@nutime.de>
 * @author Froxlor team <team@froxlor.org> (2010-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Cron
 *         
 * @since 0.9.29
 *       
 */
class DomainSSL
{

	/**
	 * read domain-related (or if empty, parentdomain-related) ssl-certificates from the database
	 * and (if not empty) set the corresponding array-indices (ssl_cert_file, ssl_key_file,
	 * ssl_ca_file and ssl_cert_chainfile).
	 * Hence the parameter as reference.
	 *
	 * @param array $domain
	 *        	domain-array as reference so we can set the corresponding array-indices
	 *        	
	 * @return null
	 */
	public function setDomainSSLFilesArray(array &$domain = null)
	{
		// check if the domain itself has a certificate defined
		$dom_certs_stmt = Database::prepare("
			SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` WHERE `domainid` = :domid
		");
		$dom_certs = Database::pexecute_first($dom_certs_stmt, array(
			'domid' => $domain['id']
		));

		if (! is_array($dom_certs) || ! isset($dom_certs['ssl_cert_file']) || $dom_certs['ssl_cert_file'] == '') {
			// maybe its parent?
			if (isset($domain['parentdomainid']) && $domain['parentdomainid'] != 0) {
				$dom_certs = Database::pexecute_first($dom_certs_stmt, array(
					'domid' => $domain['parentdomainid']
				));
			}
		}

		// check if it's an array and if the most important field is set
		if (is_array($dom_certs) && isset($dom_certs['ssl_cert_file']) && $dom_certs['ssl_cert_file'] != '') {
			// get destination path
			$sslcertpath = \Froxlor\FileDir::makeCorrectDir(Settings::Get('system.customer_ssl_path'));
			// create path if it does not exist
			if (! file_exists($sslcertpath)) {
				\Froxlor\FileDir::safe_exec('mkdir -p ' . escapeshellarg($sslcertpath));
			}
			// make correct files for the certificates
			$ssl_files = array(
				'ssl_cert_file' => \Froxlor\FileDir::makeCorrectFile($sslcertpath . '/' . $domain['domain'] . '.crt'),
				'ssl_key_file' => \Froxlor\FileDir::makeCorrectFile($sslcertpath . '/' . $domain['domain'] . '.key')
			);

			if (! $this->validateCertificate($dom_certs)) {
				\Froxlor\FroxlorLogger::getInstanceOf()->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_ERR, 'Given SSL private key for ' . $domain['domain'] . ' does not seem to match the certificate. Cannot create ssl-directives');
				return;
			}

			if (Settings::Get('system.webserver') == 'lighttpd') {
				// put my.crt and my.key together for lighty.
				$dom_certs['ssl_cert_file'] = trim($dom_certs['ssl_cert_file']) . "\n" . trim($dom_certs['ssl_key_file']) . "\n";
				$ssl_files['ssl_key_file'] = '';
			}

			// initialize optional files
			$ssl_files['ssl_ca_file'] = '';
			$ssl_files['ssl_cert_chainfile'] = '';
			// set them if they are != empty
			if ($dom_certs['ssl_ca_file'] != '') {
				$ssl_files['ssl_ca_file'] = \Froxlor\FileDir::makeCorrectFile($sslcertpath . '/' . $domain['domain'] . '_CA.pem');
			}
			if ($dom_certs['ssl_cert_chainfile'] != '') {
				if (Settings::Get('system.webserver') == 'nginx') {
					// put ca.crt in my.crt, as nginx does not support a separate chain file.
					$dom_certs['ssl_cert_file'] = trim($dom_certs['ssl_cert_file']) . "\n" . trim($dom_certs['ssl_cert_chainfile']) . "\n";
				} else {
					$ssl_files['ssl_cert_chainfile'] = \Froxlor\FileDir::makeCorrectFile($sslcertpath . '/' . $domain['domain'] . '_chain.pem');
				}
			}
			// will only be generated to be used externally, froxlor does not need this
			if ($dom_certs['ssl_fullchain_file'] != '') {
				$ssl_files['ssl_fullchain_file'] = \Froxlor\FileDir::makeCorrectFile($sslcertpath . '/' . $domain['domain'] . '_fullchain.pem');
			}
			// create them on the filesystem
			foreach ($ssl_files as $type => $filename) {
				if ($filename != '') {
					touch($filename);
					$_fh = fopen($filename, 'w');
					fwrite($_fh, $dom_certs[$type]);
					fclose($_fh);
					if ($type == 'ssl_key_file') {
						chmod($filename, 0600);
					} else {
						chmod($filename, 0644);
					}
				}
			}
			// override corresponding array values
			$domain['ssl_cert_file'] = $ssl_files['ssl_cert_file'];
			$domain['ssl_key_file'] = $ssl_files['ssl_key_file'];
			$domain['ssl_ca_file'] = $ssl_files['ssl_ca_file'];
			$domain['ssl_cert_chainfile'] = $ssl_files['ssl_cert_chainfile'];
		}

		return;
	}

	private function validateCertificate($dom_certs = array())
	{
		return openssl_x509_check_private_key($dom_certs['ssl_cert_file'], $dom_certs['ssl_key_file']);
	}
}
