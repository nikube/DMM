<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 *	\file       class/DMMDolistoreClient.class.php
 *	\ingroup    dolimodulemanager
 *	\brief      Catalog + download client for www.dolistore.com.
 *
 *  Two responsibilities:
 *    1. List products via the public API (cached on disk, TTL 24h).
 *    2. Download free modules via _service_download.php?t=free&p={id}.
 *       This endpoint serves the real ZIP only when User-Agent + Referer
 *       headers are present — without them every product (free or paid)
 *       returns the literal string "paiedProduct" (12 bytes).
 */

class DMMDolistoreClient
{
	const API_BASE     = 'https://www.dolistore.com/api/';
	const SHOP_URL     = 'https://www.dolistore.com';
	const PUBLIC_KEY   = 'dolistorepublicapi';
	const CACHE_TTL    = 86400; // 24h
	const VERSION_CACHE_TTL = 21600; // 6h, per-product version (see fetchProductVersion)
	const PRODUCTS_PER_PAGE = 20; // API hard cap: limit=21 still works, 22+ returns 403
	const WEB_PER_PAGE = 200;     // the web listing honours n=200 (n>200 returns an empty page)
	const WEB_MAX_PAGES = 10;     // 10 * 200 = 2000, above the ~1700 catalog; pages past
	                              // the last one answer 200 with zero blocks, not an error

	/** @var string */
	public $error = '';

	/** @var string */
	private $cacheDir;

	/** @var string */
	private $lang;

	public function __construct($lang = 'en_US')
	{
		global $conf;
		$this->lang = in_array($lang, array('en_US', 'fr_FR', 'es_ES', 'it_IT', 'de_DE')) ? $lang : 'en_US';
		$this->cacheDir = (isset($conf->dolimodulemanager->dir_temp) ? $conf->dolimodulemanager->dir_temp : DOL_DATA_ROOT.'/dolimodulemanager/temp').'/dolistore_cache';
		if (!is_dir($this->cacheDir)) {
			@dol_mkdir($this->cacheDir);
		}
	}

	/**
	 * Return the full product catalog, paginated then merged.
	 *
	 * Cached on disk for self::CACHE_TTL seconds. The DoliStore public API
	 * caps at 50 entries per page (limit=200 silently returns empty), so we
	 * sweep pages until total is reached.
	 *
	 * @param  bool  $forceRefresh  Bypass disk cache
	 * @return array<int,array>    List of products (raw API shape, see normalizeProduct)
	 */
	/**
	 * Whether the on-disk product catalog cache is present and still fresh.
	 * Lets callers decide to render immediately vs. warm the
	 * cache via AJAX, without triggering the (slow) full catalog download.
	 *
	 * @return bool
	 */
	public function isCatalogCached()
	{
		$cacheFile = $this->cacheDir.'/products_'.$this->lang.'.json';
		return file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL;
	}

	public function getAllProducts($forceRefresh = false)
	{
		$cacheFile = $this->cacheDir.'/products_'.$this->lang.'.json';

		if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
			$cached = @json_decode(file_get_contents($cacheFile), true);
			if (is_array($cached)) {
				return $cached;
			}
		}

		// The public API caps `limit` at 21 (22+ answers 403), so a full sweep costs
		// ~85 sequential round-trips — around 40s on a cold cache. The web listing
		// accepts n=200, so the same 1690 products come back in 9 pages we can fetch
		// in parallel, in a few seconds. Source order is configurable; the web path
		// falls back to the API on its own when parsing yields nothing, so a DoliStore
		// front-end redesign degrades to "slow" instead of "empty".
		$source = $this->catalogSource();
		if ($source !== 'api') {
			$web = $this->fetchProductsFromWeb();
			if (!empty($web)) {
				@file_put_contents($cacheFile, json_encode($web));
				return $web;
			}
			if ($source === 'web') {
				$this->error = 'DoliStore web listing returned no product';
				return array();
			}
		}

		$all = array();
		$page = 1;
		$total = null;
		while (true) {
			// Scope to category 67 (Modules/Plugins). The unfiltered listing also
			// includes books/PDFs/skins/goodies whose direct-download payload is
			// not a module zip — exposing those as installable would only produce
			// "not_a_zip" errors. Skins (cat 81) are explicitly out of scope: a
			// theme is loaded by Dolibarr core and does not go through DMM.
			$resp = $this->callApi('products', array(
				'lang' => $this->lang,
				'limit' => self::PRODUCTS_PER_PAGE,
				'page' => $page,
				'categorieid' => 67,
			));
			if ($resp === null || !isset($resp['products']) || !is_array($resp['products'])) {
				break;
			}
			foreach ($resp['products'] as $p) {
				$all[] = $p;
			}
			if ($total === null && isset($resp['total'])) {
				$total = (int) $resp['total'];
			}
			if (count($resp['products']) < self::PRODUCTS_PER_PAGE) {
				break;
			}
			if ($total !== null && count($all) >= $total) {
				break;
			}
			$page++;
			if ($page > 200) { // hard safety: 200 * 20 = 4000 modules
				break;
			}
		}

		if (!empty($all)) {
			@file_put_contents($cacheFile, json_encode($all));
		}
		return $all;
	}

	/**
	 * Which catalog source to use: 'auto' (web, API fallback), 'web' or 'api'.
	 * Settable from the Advanced tab; unknown values fall back to 'auto'.
	 *
	 * @return string
	 */
	private function catalogSource()
	{
		if (!function_exists('dmm_get_setting')) {
			dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');
		}
		if (!function_exists('dmm_get_setting')) {
			return 'auto';
		}
		$v = (string) dmm_get_setting('catalog_source', 'auto');
		return in_array($v, array('auto', 'web', 'api'), true) ? $v : 'auto';
	}

	/**
	 * Fetch the whole catalog from the public web listing, which honours n=200 and
	 * therefore needs only ~9 pages. Pages are fetched concurrently with curl_multi.
	 *
	 * Returns the same shape as the API path (id/label/description/dolibarr_min/
	 * dolibarr_max/price_ht/cover_photo_url) so callers cannot tell the two apart.
	 * Returns an empty array on any parsing failure, which is the fallback signal.
	 *
	 * @return array<int,array>
	 */
	private function fetchProductsFromWeb()
	{
		if (!function_exists('curl_multi_init') || !class_exists('DOMDocument')) {
			return array();
		}

		$lang = substr($this->lang, 0, 2);
		$urls = array();
		for ($page = 1; $page <= self::WEB_MAX_PAGES; $page++) {
			$urls[$page] = self::SHOP_URL.'/index.php?'.http_build_query(array(
				'cat' => 67, // Modules/Plugins, same scope as the API sweep
				'title' => 'modules-plugins',
				'l' => $lang,
				'n' => self::WEB_PER_PAGE,
				'p' => $page,
			));
		}

		$mh = curl_multi_init();
		$handles = array();
		foreach ($urls as $page => $url) {
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_USERAGENT => $this->browserUserAgent(),
				CURLOPT_ENCODING => '', // accept gzip: these pages are ~850 KB raw
			));
			curl_multi_add_handle($mh, $ch);
			$handles[$page] = $ch;
		}
		do {
			curl_multi_exec($mh, $running);
			curl_multi_select($mh, 1.0);
		} while ($running > 0);

		$all = array();
		$failed = 0;
		foreach ($handles as $ch) {
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$body = curl_multi_getcontent($ch);
			if ($code === 200 && is_string($body) && $body !== '') {
				foreach ($this->parseWebListing($body) as $id => $product) {
					if (!isset($all[$id])) {
						$all[$id] = $product;
					}
				}
			} else {
				$failed++;
			}
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}
		curl_multi_close($mh);

		// A partial sweep would silently truncate the catalog, so treat any failed
		// page as a total failure and let the caller fall back to the API.
		if ($failed > 0) {
			$this->error = 'DoliStore web listing: '.$failed.' page(s) failed';
			return array();
		}

		return array_values($all);
	}

	/**
	 * Extract products from one web listing page.
	 *
	 * @param  string $html Raw HTML
	 * @return array<int,array> Keyed by product id
	 */
	private function parseWebListing($html)
	{
		libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		// The listing is UTF-8 but ships no meta charset early enough for libxml.
		if (!$doc->loadHTML('<?xml encoding="UTF-8">'.$html)) {
			libxml_clear_errors();
			return array();
		}
		libxml_clear_errors();
		$xp = new DOMXPath($doc);

		$blocks = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' ajax_block_product ')]");
		if ($blocks === false || $blocks->length === 0) {
			return array();
		}

		$out = array();
		foreach ($blocks as $block) {
			$link = $xp->query(".//a[contains(@class, 'product-name')]", $block)->item(0);
			if (!$link) {
				$link = $xp->query(".//a[contains(@href, 'product.php?id=')]", $block)->item(0);
			}
			if (!$link || !preg_match('/[?&]id=(\d+)/', $link->getAttribute('href'), $m)) {
				continue;
			}
			$id = (int) $m[1];
			if ($id <= 0 || isset($out[$id])) {
				continue;
			}

			$label = trim($link->textContent);
			if ($label === '') {
				continue;
			}
			// The anchor's title attribute carries the full product description.
			$description = trim($link->getAttribute('title'));

			$dolibarrMin = '';
			$dolibarrMax = '';
			$versionNode = $xp->query(".//*[contains(@class, 'version-label')]", $block)->item(0);
			if ($versionNode && preg_match('/V(\d+)\s*-\s*V(\d+)/i', $versionNode->textContent, $vm)) {
				$dolibarrMin = 'V'.$vm[1];
				$dolibarrMax = 'V'.$vm[2];
			}

			$priceText = '';
			$priceNode = $xp->query(".//*[contains(@class, 'product-price')]", $block)->item(0);
			if ($priceNode) {
				$priceText = trim(preg_replace('/\s+/', ' ', $priceNode->textContent));
			}
			$priceHt = '';
			if (preg_match('/([\d\s.,]+)\s*€/u', $priceText, $pm)) {
				$priceHt = str_replace(array(' ', ','), array('', '.'), trim($pm[1]));
			}

			$cover = '';
			$img = $xp->query(".//img[contains(@class, 'replace-2x')]", $block)->item(0);
			if ($img) {
				$cover = $img->getAttribute('src');
			}

			$out[$id] = array(
				'id' => (string) $id,
				'ref' => '',
				'datec' => '',
				'price_ht' => $priceHt,
				'price_ttc' => '',
				'label' => $label,
				'description' => $description,
				'tms' => '',
				'dolibarr_min' => $dolibarrMin,
				'dolibarr_max' => $dolibarrMax,
				'module_version' => '',
				'cover_photo_url' => $cover,
			);
		}
		return $out;
	}

	/**
	 * Find a product by its DoliStore id.
	 *
	 * @param  int        $id
	 * @param  bool       $forceRefresh
	 * @return array|null Product entry or null if missing
	 */
	public function findProductById($id, $forceRefresh = false)
	{
		$id = (int) $id;
		if ($id <= 0) {
			return null;
		}
		foreach ($this->getAllProducts($forceRefresh) as $p) {
			if ((int) ($p['id'] ?? 0) === $id) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Resolve the published version of a single product, independently of the
	 * catalog source.
	 *
	 * An update check needs one version, but findProductById() answers out of the
	 * whole cached catalog — and only one of the two sources that build it carries
	 * the version. The web listing does not expose it (parseWebListing() writes
	 * module_version => ''), and the web path is what 'auto' prefers, so on a
	 * default install every DoliStore module silently reported "no update ever".
	 * Filling the field in the listing parser is not an option: the version only
	 * appears on the product page, so a complete catalog would cost ~1700 extra
	 * requests. Resolving one product on demand is the right granularity.
	 *
	 * @param  int         $id           DoliStore product id
	 * @param  bool        $forceRefresh Bypass the per-product cache
	 * @return string|null               Version, or null when it cannot be resolved
	 */
	public function fetchProductVersion($id, $forceRefresh = false)
	{
		$id = (int) $id;
		if ($id <= 0) {
			return null;
		}

		if (!function_exists('dmm_get_setting')) {
			dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');
		}
		$hasSettings = function_exists('dmm_get_setting') && function_exists('dmm_set_setting');
		$cacheKey = 'dolistore_version_'.$id;

		// A published version changes rarely, but far more often than the 24h
		// catalog: keep it short enough that an update shows up the same day.
		if (!$forceRefresh && $hasSettings) {
			$cached = (string) dmm_get_setting($cacheKey, '');
			if ($cached !== '') {
				$parts = explode('|', $cached, 2);
				if (count($parts) === 2 && (time() - (int) $parts[0]) < self::VERSION_CACHE_TTL) {
					return $parts[1] !== '' ? $parts[1] : null;
				}
			}
		}

		// Already in the catalog with a usable value? Then the API source built it
		// and there is nothing to fetch — this keeps 'api' mode request-free.
		$product = $this->findProductById($id);
		$fromCatalog = $product !== null ? trim((string) ($product['module_version'] ?? '')) : '';
		if ($fromCatalog !== '') {
			if ($hasSettings) {
				dmm_set_setting($cacheKey, time().'|'.$fromCatalog);
			}
			return $fromCatalog;
		}

		// No direct product endpoint exists: /api/products/{id} answers 404 and the
		// listing ignores an id filter, so the product page is the only source.
		$html = $this->fetchProductPage($id);
		if ($html === null) {
			return null;
		}
		$version = $this->parseProductVersion($html);

		// Cache the negative too — a product with no published version would
		// otherwise be re-fetched on every single check.
		if ($hasSettings) {
			dmm_set_setting($cacheKey, time().'|'.((string) $version));
		}

		return $version;
	}

	/**
	 * Download a product page. Split out so the parser can be tested on a fixture.
	 *
	 * @param  int         $id DoliStore product id
	 * @return string|null     HTML body, or null on transport failure
	 */
	private function fetchProductPage($id)
	{
		$url = self::SHOP_URL.'/product.php?'.http_build_query(array('id' => (int) $id));

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_USERAGENT => $this->browserUserAgent(),
			CURLOPT_ENCODING => '',
		));
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $code !== 200 || $body === '') {
			$this->error = 'DoliStore product page error: HTTP '.$code;
			return null;
		}
		return (string) $body;
	}

	/**
	 * Extract the module version from a product page.
	 *
	 * The info box is a list of `<li><b>Label</b><span class="infos-module">value`
	 * rows. The label is localized ("Module version" / "Version du module"), so
	 * matching on its text would break per language; instead take the first
	 * infos-module value that looks like a version number. Author and dates sit in
	 * the same box and cannot be mistaken for one. A product with no published
	 * version simply has no such row — that must read as null, not "".
	 *
	 * @param  string      $html Product page HTML
	 * @return string|null       Version, or null when the page carries none
	 */
	private function parseProductVersion($html)
	{
		if (!class_exists('DOMDocument')) {
			return null;
		}

		$prev = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$loaded = $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$loaded) {
			return null;
		}

		$xp = new DOMXPath($doc);
		$nodes = $xp->query("//*[contains(@class, 'info-list-box')]//span[contains(@class, 'infos-module')]");
		if ($nodes === false) {
			return null;
		}

		foreach ($nodes as $node) {
			$value = trim($node->textContent);
			// A version is digits and dots, optionally with a suffix (1.0, 2.0.16,
			// 3.4.1-beta). Dates in the same box (01/25/2023) carry slashes and are
			// rejected, as is a free-text author.
			if (preg_match('/^\d+(?:\.\d+)+(?:[-.][A-Za-z0-9]+)?$/', $value)) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Test if a DoliStore product is downloadable anonymously.
	 *
	 * The marker is the response Content-Disposition header: a real ZIP
	 * download carries `attachment; filename="*.zip"`, while a paid product
	 * returns the 12-byte string "paiedProduct" with text/html.
	 *
	 * @param  int  $id  DoliStore product id
	 * @return bool
	 */
	public function isFree($id)
	{
		$id = (int) $id;
		if ($id <= 0) {
			return false;
		}
		$url = self::SHOP_URL.'/_service_download.php?t=free&p='.$id;
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_NOBODY => true,
			CURLOPT_HEADER => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_HTTPHEADER => array(
				'User-Agent: '.$this->browserUserAgent(),
				'Referer: '.self::SHOP_URL.'/product.php?id='.$id,
			),
		));
		$resp = curl_exec($ch);
		curl_close($ch);
		if ($resp === false) {
			return false;
		}
		return (bool) preg_match('/Content-Disposition:\s*attachment;\s*filename="?[^"\r\n]+\.zip/i', $resp);
	}

	/**
	 * Download a free DoliStore module zip into $dest.
	 *
	 * @param  int    $id    DoliStore product id
	 * @param  string $dest  Absolute path where the zip will be written
	 * @return array{ok:bool,filename:?string,error:?string}
	 */
	public function downloadFreeZip($id, $dest)
	{
		$id = (int) $id;
		if ($id <= 0) {
			return array('ok' => false, 'filename' => null, 'error' => 'invalid_id');
		}
		$url = self::SHOP_URL.'/_service_download.php?t=free&p='.$id;
		$fp = @fopen($dest, 'wb');
		if (!$fp) {
			return array('ok' => false, 'filename' => null, 'error' => 'cannot_write_'.$dest);
		}
		$filename = null;
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_HTTPHEADER => array(
				'User-Agent: '.$this->browserUserAgent(),
				'Referer: '.self::SHOP_URL.'/product.php?id='.$id,
			),
			CURLOPT_HEADERFUNCTION => function ($curl, $hdr) use (&$filename) {
				if (preg_match('/Content-Disposition:\s*attachment;\s*filename="?([^";\r\n]+)/i', $hdr, $m)) {
					$filename = trim($m[1], "\" ");
				}
				return strlen($hdr);
			},
		));
		$ok = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);

		if ($ok === false || $httpCode !== 200) {
			@unlink($dest);
			return array('ok' => false, 'filename' => null, 'error' => 'http_'.$httpCode);
		}

		// Reject the "paiedProduct" sentinel response.
		clearstatcache(true, $dest);
		$size = filesize($dest);
		if ($size !== false && $size < 200) {
			$head = @file_get_contents($dest, false, null, 0, 64);
			if ($head !== false && strpos($head, 'paiedProduct') !== false) {
				@unlink($dest);
				return array('ok' => false, 'filename' => null, 'error' => 'paid_product');
			}
		}

		// Sanity-check ZIP magic.
		$head = @file_get_contents($dest, false, null, 0, 4);
		if ($head === false || substr($head, 0, 2) !== 'PK') {
			@unlink($dest);
			return array('ok' => false, 'filename' => null, 'error' => 'not_a_zip');
		}

		return array('ok' => true, 'filename' => $filename, 'error' => null);
	}

	/**
	 * Convert a raw API product to the DMM marketplace shape.
	 *
	 * @param  array $p  Raw product (12 fields, see API doc above)
	 * @return array
	 */
	public function normalizeProduct(array $p)
	{
		$id = (int) ($p['id'] ?? 0);
		$priceHt = (float) ($p['price_ht'] ?? 0);
		return array(
			'id' => $id,
			'source' => 'dolistore',
			'ref' => $p['ref'] ?? '',
			'label' => $p['label'] ?? '',
			'description' => $p['description'] ?? '',
			'datec' => $p['datec'] ?? '',
			'tms' => $p['tms'] ?? '',
			'price_ht' => $priceHt,
			'price_ttc' => (float) ($p['price_ttc'] ?? 0),
			'dolibarr_min' => $p['dolibarr_min'] ?? 'unknown',
			'dolibarr_max' => $p['dolibarr_max'] ?? 'unknown',
			'module_version' => $p['module_version'] ?? '',
			'cover_photo_url' => !empty($p['cover_photo_url']) ? $this->absolutizeCover($p['cover_photo_url']) : '',
			'view_url' => self::SHOP_URL.'/product.php?id='.$id,
			'is_free_candidate' => ($priceHt === 0.0),
		);
	}

	/**
	 * Issue an authenticated GET against the public DoliStore API.
	 *
	 * @param  string $resource  e.g. 'products', 'categories'
	 * @param  array  $params    Query params (DOLAPIKEY is added automatically)
	 * @return array|null        Decoded JSON or null on error
	 */
	private function callApi($resource, array $params)
	{
		$params['apikey'] = self::PUBLIC_KEY;
		$url = self::API_BASE.$resource.'?'.http_build_query($params);

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array(
				'DOLAPIKEY: '.self::PUBLIC_KEY,
				'User-Agent: DMM/1.0',
			),
			CURLOPT_TIMEOUT => 15,
			CURLOPT_FOLLOWLOCATION => true,
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $code !== 200) {
			$this->error = 'DoliStore API error: HTTP '.$code;
			return null;
		}
		$data = json_decode($body, true);
		return is_array($data) ? $data : null;
	}

	private function absolutizeCover($path)
	{
		if (preg_match('#^https?://#i', $path)) {
			return $path;
		}
		return self::SHOP_URL.(substr($path, 0, 1) === '/' ? '' : '/').$path;
	}

	private function browserUserAgent()
	{
		return 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
	}
}
