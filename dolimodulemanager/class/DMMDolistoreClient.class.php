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
	const PRODUCTS_PER_PAGE = 20; // hard upstream cap (limit > 20 returns 403)

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
	public function getAllProducts($forceRefresh = false)
	{
		$cacheFile = $this->cacheDir.'/products_'.$this->lang.'.json';

		if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
			$cached = @json_decode(file_get_contents($cacheFile), true);
			if (is_array($cached)) {
				return $cached;
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
