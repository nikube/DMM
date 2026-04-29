<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 *	\file       class/DMMDolistoreSession.class.php
 *	\ingroup    dolimodulemanager
 *	\brief      Authenticated session against www.dolistore.com.
 *
 *  Used by the "My DoliStore purchases" tab to:
 *    1. Reuse a session cookie pasted from the user's browser, OR
 *       fall back to email+password auto-login if cookie is empty/expired.
 *    2. Scrape order-history.php to list purchased modules.
 *    3. Download paid module ZIPs via the wrapper.php link surfaced by the
 *       order history table.
 *
 *  Design contract (unpluggable):
 *    - Every method catches all errors internally and returns a structured
 *      state. No exceptions ever leak. If the network is down, DoliStore
 *      changes layout, or curl is missing, the caller gets a clean error
 *      flag and the rest of DMM keeps working.
 *    - Credentials live in llx_dmm_setting, encrypted via dolEncrypt
 *      (same pattern as DMMToken).
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

class DMMDolistoreSession
{
	const SHOP_URL    = 'https://www.dolistore.com';
	const LOGIN_URL   = 'https://www.dolistore.com/login-register.php';
	const ACCOUNT_URL = 'https://www.dolistore.com/myaccount.php';
	const HISTORY_URL = 'https://www.dolistore.com/order-history.php';
	const USER_AGENT  = 'Mozilla/5.0 (X11; Linux x86_64; rv:149.0) Gecko/20100101 Firefox/149.0';

	const HTTP_TIMEOUT = 30;
	const SESSION_FILE = 'dolistore_session.cookies';

	/** @var DoliDB */
	private $db;

	/** @var string Last technical error (for logs / collapsible UI) */
	public $error = '';

	/** @var string Reason code for UI: '', 'no_creds', 'login_failed', 'network', 'parse', 'no_dom' */
	public $errorCode = '';

	/** @var string Path to the cookie jar file used by curl */
	private $cookieJar;

	/** @var string|null Decrypted cookie pasted by the user, or null */
	private $userCookie;

	/** @var string|null Decrypted email (auto-login fallback) */
	private $email;

	/** @var string|null Decrypted password (auto-login fallback) */
	private $password;

	/**
	 * @param DoliDB $db Database handle (settings live in llx_dmm_setting)
	 */
	public function __construct($db)
	{
		global $conf;
		$this->db = $db;

		$baseTemp = isset($conf->dolimodulemanager->dir_temp)
			? $conf->dolimodulemanager->dir_temp
			: DOL_DATA_ROOT.'/dolimodulemanager/temp';
		if (!is_dir($baseTemp)) {
			@dol_mkdir($baseTemp);
		}
		$this->cookieJar = $baseTemp.'/'.self::SESSION_FILE;

		// Lazy-load credentials so a fresh install with no settings doesn't error.
		$this->loadCredentials();
	}

	/**
	 * Read cookie + email/password from llx_dmm_setting (decrypted).
	 *
	 * @return void
	 */
	private function loadCredentials()
	{
		if (!function_exists('dmm_get_setting')) {
			dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');
		}

		$rawCookie = dmm_get_setting('dolistore_cookie', '');
		$this->userCookie = $rawCookie ? dolDecrypt($rawCookie) : null;
		if ($this->userCookie === '') {
			$this->userCookie = null;
		}

		$this->email = dmm_get_setting('dolistore_email', '') ?: null;

		$rawPw = dmm_get_setting('dolistore_password', '');
		$this->password = $rawPw ? dolDecrypt($rawPw) : null;
		if ($this->password === '') {
			$this->password = null;
		}
	}

	/**
	 * Return true if at least one auth method is configured.
	 *
	 * @return bool
	 */
	public function hasCredentials()
	{
		return $this->userCookie !== null || ($this->email && $this->password);
	}

	/**
	 * Ensure curl + dom extensions are present. Cached check.
	 *
	 * @return string|null  Missing extension name, or null if all good.
	 */
	public function checkExtensions()
	{
		if (!function_exists('curl_init')) {
			return 'curl';
		}
		if (!class_exists('DOMDocument')) {
			return 'dom';
		}
		return null;
	}

	/**
	 * Verify the current session is alive by GETting myaccount.php.
	 * If a user-pasted cookie exists, it is primed into the cookie jar first.
	 *
	 * @return bool
	 */
	public function verifySession()
	{
		$missing = $this->checkExtensions();
		if ($missing !== null) {
			$this->error = 'Missing PHP extension: '.$missing;
			$this->errorCode = 'no_dom';
			return false;
		}
		if (!$this->hasCredentials()) {
			$this->error = 'No DoliStore credentials configured';
			$this->errorCode = 'no_creds';
			return false;
		}

		$this->primeCookieJarIfNeeded();

		$resp = $this->httpGet(self::ACCOUNT_URL);
		if ($resp === null) {
			return false;
		}
		// session.py:78 — logged-in marker is the logout link
		return strpos($resp['body'], 'action=logout') !== false;
	}

	/**
	 * Try to log in using stored email + password (the fallback path).
	 *
	 * Mirrors dolistore-publisher/dolistore/session.py:43-69.
	 *
	 * @return bool
	 */
	public function tryLoginWithPassword()
	{
		if (!$this->email || !$this->password) {
			$this->error = 'No password fallback configured';
			$this->errorCode = 'no_creds';
			return false;
		}

		// Wipe any prior cookie jar — we don't want stale PHPSESSID poisoning the POST.
		@unlink($this->cookieJar);

		// Prime cookies (PHPSESSID) by GET login page first.
		$this->httpGet(self::LOGIN_URL);

		$payload = http_build_query(array(
			'email' => $this->email,
			'passwd' => $this->password,
			'action' => 'login',
			'back' => '',
			'SubmitLogin' => '',
		));

		$ch = curl_init(self::LOGIN_URL);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
			CURLOPT_COOKIEJAR => $this->cookieJar,
			CURLOPT_COOKIEFILE => $this->cookieJar,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER => array(
				'User-Agent: '.self::USER_AGENT,
				'Accept-Language: fr,en;q=0.7',
				'Referer: '.self::LOGIN_URL,
				'Origin: '.self::SHOP_URL,
				'Content-Type: application/x-www-form-urlencoded',
			),
		));
		$raw = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false) {
			$this->error = 'Login network error';
			$this->errorCode = 'network';
			return false;
		}

		// Login success = 302 with /myaccount.php in Location header.
		if ($code !== 302) {
			$this->error = 'Login failed (HTTP '.$code.')';
			$this->errorCode = 'login_failed';
			return false;
		}
		if (!preg_match('/^Location:\s*([^\r\n]+)/im', $raw, $m)) {
			$this->error = 'Login failed (no redirect)';
			$this->errorCode = 'login_failed';
			return false;
		}
		if (strpos($m[1], '/myaccount.php') === false) {
			$this->error = 'Login failed (wrong redirect)';
			$this->errorCode = 'login_failed';
			return false;
		}

		return true;
	}

	/**
	 * Fetch the list of purchased modules.
	 *
	 * Two-pass scrape against the buyer-side pages:
	 *   1. order-history.php → list of order refs (each cart_ref like CO2602-45689)
	 *   2. order-details.php?ref={ref} → per-product rows with the real
	 *      _service_download.php?t=paied&r=...&p=...&u=... link.
	 *
	 * The publisher-side scrape (table.order-history tbody tr.item) does not
	 * apply here: that markup is the seller's manage-products.php, not the
	 * buyer's order history. Selectors confirmed empirically against the
	 * production storefront on 2026-04-29.
	 *
	 * @return array{ok:bool, products:array, error:?string}
	 *         products: list of arrays with keys id, name, ref (order ref),
	 *         status, expires, zip_url
	 */
	public function fetchPurchases()
	{
		$missing = $this->checkExtensions();
		if ($missing !== null) {
			$this->error = 'Missing PHP extension: '.$missing;
			$this->errorCode = 'no_dom';
			return array('ok' => false, 'products' => array(), 'error' => $this->error);
		}
		if (!$this->hasCredentials()) {
			$this->error = 'No credentials';
			$this->errorCode = 'no_creds';
			return array('ok' => false, 'products' => array(), 'error' => $this->error);
		}

		// Try the existing session first; if dead, attempt password re-login.
		if (!$this->verifySession()) {
			if (!$this->tryLoginWithPassword()) {
				return array('ok' => false, 'products' => array(), 'error' => $this->error ?: 'session invalid');
			}
		}

		$resp = $this->httpGet(self::HISTORY_URL);
		if ($resp === null) {
			return array('ok' => false, 'products' => array(), 'error' => $this->error);
		}

		$orderRefs = $this->parseOrderRefs($resp['body']);
		if (empty($orderRefs)) {
			return array('ok' => true, 'products' => array(), 'error' => null);
		}

		$products = array();
		$seen = array();
		foreach ($orderRefs as $ref) {
			$detailResp = $this->httpGet(self::SHOP_URL.'/order-details.php?ref='.urlencode($ref));
			if ($detailResp === null) {
				continue;
			}
			foreach ($this->parseOrderDetails($detailResp['body'], $ref) as $p) {
				$key = (int) $p['id'];
				// Same module on multiple orders → keep the most recent (latest in list).
				$seen[$key] = $p;
			}
		}
		foreach ($seen as $p) {
			$products[] = $p;
		}
		return array('ok' => true, 'products' => $products, 'error' => null);
	}

	/**
	 * Download a purchased ZIP via the wrapper.php link from order-history.
	 *
	 * @param  string $wrapperUrl Absolute or shop-relative URL ending in wrapper.php?...
	 * @param  string $dest       Destination absolute path
	 * @return array{ok:bool,error:?string}
	 */
	public function downloadPurchaseZip($wrapperUrl, $dest)
	{
		if (empty($wrapperUrl)) {
			return array('ok' => false, 'error' => 'no_wrapper_url');
		}
		if (!$this->hasCredentials()) {
			return array('ok' => false, 'error' => 'no_creds');
		}

		// Normalise to absolute URL.
		if (strpos($wrapperUrl, 'http') !== 0) {
			$wrapperUrl = self::SHOP_URL.(substr($wrapperUrl, 0, 1) === '/' ? '' : '/').$wrapperUrl;
		}

		// Make sure session is alive (cheap guard before a potentially big download).
		if (!$this->verifySession()) {
			if (!$this->tryLoginWithPassword()) {
				return array('ok' => false, 'error' => 'session_invalid');
			}
		}

		$fp = @fopen($dest, 'wb');
		if (!$fp) {
			return array('ok' => false, 'error' => 'cannot_write');
		}

		// If the URL embeds the order ref (typical for _service_download.php?t=paied&r=...),
		// use the matching order-details page as Referer so the storefront's
		// anti-hotlinking checks (if any) are satisfied. Falls back to the
		// history page otherwise.
		$referer = self::HISTORY_URL;
		if (preg_match('/[?&]r=([A-Z0-9-]+)/', $wrapperUrl, $m)) {
			$referer = self::SHOP_URL.'/order-details.php?ref='.$m[1];
		}

		$ch = curl_init($wrapperUrl);
		curl_setopt_array($ch, array(
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_COOKIEJAR => $this->cookieJar,
			CURLOPT_COOKIEFILE => $this->cookieJar,
			CURLOPT_HTTPHEADER => array(
				'User-Agent: '.self::USER_AGENT,
				'Accept-Language: fr,en;q=0.7',
				'Referer: '.$referer,
			),
		));
		$ok = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		curl_close($ch);
		fclose($fp);

		if ($ok === false || $httpCode !== 200) {
			@unlink($dest);
			return array('ok' => false, 'error' => 'http_'.$httpCode);
		}

		// If the server gave us HTML back, the session is dead and we've just
		// downloaded the login page. Fail loudly so the caller can prompt the
		// user to refresh their cookie.
		if (stripos((string) $contentType, 'text/html') !== false) {
			@unlink($dest);
			return array('ok' => false, 'error' => 'session_invalid');
		}

		// Sanity-check ZIP magic.
		$head = @file_get_contents($dest, false, null, 0, 4);
		if ($head === false || substr($head, 0, 2) !== 'PK') {
			@unlink($dest);
			return array('ok' => false, 'error' => 'not_a_zip');
		}

		return array('ok' => true, 'error' => null);
	}

	/**
	 * Persist the user-pasted cookie into the cookie jar so curl can use it.
	 * Accepts either the bare PHPSESSID value or a "key=value; key=value" header.
	 *
	 * @return void
	 */
	private function primeCookieJarIfNeeded()
	{
		if ($this->userCookie === null) {
			return;
		}
		// Avoid re-priming on every call: check if the jar already has it.
		if (file_exists($this->cookieJar) && (time() - filemtime($this->cookieJar)) < 60) {
			return;
		}

		$cookies = $this->parseCookieString($this->userCookie);
		if (empty($cookies)) {
			return;
		}

		// Netscape cookie file format: domain \t flag \t path \t secure \t expiry \t name \t value
		$lines = array("# Netscape HTTP Cookie File\n");
		$expiry = time() + 86400 * 30;
		foreach ($cookies as $name => $value) {
			$lines[] = ".dolistore.com\tTRUE\t/\tTRUE\t".$expiry."\t".$name."\t".$value."\n";
		}
		@file_put_contents($this->cookieJar, implode('', $lines));
	}

	/**
	 * Parse "PHPSESSID=abc; foo=bar" or just "abc" (assumed PHPSESSID) into a map.
	 *
	 * @param  string $raw
	 * @return array<string,string>
	 */
	private function parseCookieString($raw)
	{
		$raw = trim($raw);
		if ($raw === '') {
			return array();
		}
		$out = array();
		if (strpos($raw, '=') === false) {
			// Bare value → assume PHPSESSID.
			$out['PHPSESSID'] = $raw;
			return $out;
		}
		foreach (explode(';', $raw) as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}
			$eq = strpos($part, '=');
			if ($eq === false) {
				continue;
			}
			$k = trim(substr($part, 0, $eq));
			$v = trim(substr($part, $eq + 1));
			if ($k !== '') {
				$out[$k] = $v;
			}
		}
		return $out;
	}

	/**
	 * Authenticated GET via the cookie jar.
	 *
	 * @param  string $url
	 * @return array{body:string,code:int}|null  null on network failure
	 */
	private function httpGet($url)
	{
		$this->primeCookieJarIfNeeded();
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
			CURLOPT_COOKIEJAR => $this->cookieJar,
			CURLOPT_COOKIEFILE => $this->cookieJar,
			CURLOPT_HTTPHEADER => array(
				'User-Agent: '.self::USER_AGENT,
				'Accept-Language: fr,en;q=0.7',
			),
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false) {
			$this->error = 'Network error fetching '.$url;
			$this->errorCode = 'network';
			return null;
		}
		return array('body' => $body, 'code' => $code);
	}

	/**
	 * Build a DOMXPath from raw HTML, suppressing libxml noise.
	 *
	 * @param  string $html
	 * @return DOMXPath|null
	 */
	private function makeXPath($html)
	{
		if ($html === '') {
			return null;
		}
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$loaded = @$dom->loadHTML($html);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$loaded) {
			return null;
		}
		return new DOMXPath($dom);
	}

	/**
	 * Extract the list of order refs (cart_ref like CO2602-45689) from
	 * order-history.php. Each ref maps to a /order-details.php?ref=… page
	 * that holds the actual per-product download links.
	 *
	 * @param  string $html
	 * @return array<int,string>
	 */
	private function parseOrderRefs($html)
	{
		$xp = $this->makeXPath($html);
		if ($xp === null) {
			return array();
		}
		// table.order-history tbody tr.item span.cart_ref → "CO2602-45689"
		$rows = $xp->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' order-history ')]//tbody/tr[contains(concat(' ', normalize-space(@class), ' '), ' item ')]//span[contains(concat(' ', normalize-space(@class), ' '), ' cart_ref ')]");
		if ($rows === false) {
			return array();
		}
		$refs = array();
		foreach ($rows as $node) {
			$ref = trim($node->textContent);
			if ($ref !== '' && preg_match('/^[A-Z0-9-]+$/', $ref)) {
				$refs[] = $ref;
			}
		}
		return $refs;
	}

	/**
	 * Parse a single order-details.php page and return the list of downloadable
	 * products. Rows that carry no download_icon (eg. "Optional contribution
	 * to the development of regulatory modules") are skipped.
	 *
	 * Pattern: <tr class="item">
	 *            <td class="cart_description">
	 *              <p class="product-name"><a href="product.php?id=PID">NAME</a></p>
	 *              <small class="cart_ref">Réf. : c868d20180302165728</small>
	 *            </td>
	 *            <td class="download_icon">
	 *              <a href="_service_download.php?t=paied&r=ORDER&p=PID&u=UID">…</a>
	 *              <small>Expiré le : 18/08/2026</small>
	 *            </td>
	 *            …
	 *          </tr>
	 *
	 * @param  string $html
	 * @param  string $orderRef
	 * @return array<int,array>
	 */
	private function parseOrderDetails($html, $orderRef)
	{
		$xp = $this->makeXPath($html);
		if ($xp === null) {
			return array();
		}
		// Order-details has its own block-order-detail table; the cart_description
		// + download_icon pair lives in tr.item rows inside it.
		$rows = $xp->query("//tr[contains(concat(' ', normalize-space(@class), ' '), ' item ')][.//td[contains(concat(' ', normalize-space(@class), ' '), ' download_icon ')]]");
		if ($rows === false || $rows->length === 0) {
			return array();
		}

		$products = array();
		foreach ($rows as $row) {
			$descNode = $xp->query(".//td[contains(concat(' ', normalize-space(@class), ' '), ' cart_description ')]", $row)->item(0);
			$dlNode = $xp->query(".//td[contains(concat(' ', normalize-space(@class), ' '), ' download_icon ')]", $row)->item(0);
			if (!$descNode || !$dlNode) {
				continue;
			}
			$dlLink = $xp->query(".//a[contains(@href, '_service_download.php')]", $dlNode)->item(0);
			if (!$dlLink) {
				continue;
			}
			$zipUrl = html_entity_decode($dlLink->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

			$id = 0;
			$name = '';
			$nameLink = $xp->query(".//p[contains(concat(' ', normalize-space(@class), ' '), ' product-name ')]/a[contains(@href, 'product.php?id=')]", $descNode)->item(0);
			if ($nameLink) {
				$name = trim($nameLink->textContent);
				if (preg_match('/[?&]id=(\d+)/', $nameLink->getAttribute('href'), $m)) {
					$id = (int) $m[1];
				}
			}
			// Fallback: pull product id from the download URL itself.
			if ($id === 0 && preg_match('/[?&]p=(\d+)/', $zipUrl, $m)) {
				$id = (int) $m[1];
			}
			if ($id === 0) {
				continue;
			}

			$expires = '';
			$expNode = $xp->query(".//small", $dlNode)->item(0);
			if ($expNode) {
				$expires = trim($expNode->textContent);
			}

			$products[] = array(
				'id' => $id,
				'name' => $name !== '' ? $name : ('Module #'.$id),
				'version' => '', // not surfaced on the order page; checked through the public API on demand
				'ref' => $orderRef,
				'status' => '',
				'expires' => $expires,
				'doli_range' => '',
				'zip_url' => $zipUrl,
			);
		}
		return $products;
	}
}
