<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/DolistoreVersionTest.php
 * \ingroup dolimodulemanager
 * \brief   Regression tests for resolving a DoliStore product version.
 *
 * checkDolistoreUpdate() read module_version out of the cached catalog, but only
 * one of the two sources that build it carries the field: the API fills it, the
 * web listing writes ''. 'auto' prefers the web path (2.8s vs 39s), so on a
 * default install every DoliStore module reported "no update" forever — with no
 * error, just an empty "Latest" on the card. Reproduced with product 623
 * (Stancer): 2.0.10 installed, 2.0.16 published.
 *
 * The parser is what the fallback rests on, so it is pinned against real pages
 * captured on 2026-08-09 (see fixtures/dolistore_product_*.html).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

final class DolistoreVersionTest extends TestCase
{
	/** @var DMMDolistoreClient */
	private $client;

	protected function setUp(): void
	{
		require_once __DIR__.'/../class/DMMDolistoreClient.class.php';
		$this->client = new DMMDolistoreClient();
	}

	/**
	 * Call a private method under test.
	 *
	 * @param  string $name Method name
	 * @param  array  $args Arguments
	 * @return mixed
	 */
	private function callPrivate($name, array $args)
	{
		$ref = new ReflectionMethod('DMMDolistoreClient', $name);
		$ref->setAccessible(true);
		return $ref->invokeArgs($this->client, $args);
	}

	/**
	 * Load a captured product page.
	 *
	 * @param  string $name Fixture basename
	 * @return string
	 */
	private function fixture($name)
	{
		return file_get_contents(__DIR__.'/fixtures/'.$name);
	}

	/**
	 * The real page for product 623 publishes 2.0.16.
	 *
	 * @return void
	 */
	public function testReadsVersionFromProductPage()
	{
		$this->assertSame(
			'2.0.16',
			$this->callPrivate('parseProductVersion', array($this->fixture('dolistore_product_623.html')))
		);
	}

	/**
	 * The label is localized ("Version du module"), the value is not — so the
	 * parser must not key on the label text.
	 *
	 * @return void
	 */
	public function testReadsVersionFromLocalizedPage()
	{
		$this->assertSame(
			'2.0.16',
			$this->callPrivate('parseProductVersion', array($this->fixture('dolistore_product_fr.html')))
		);
	}

	/**
	 * A product with no published version has no version row at all. It must read
	 * as null — the caller distinguishes "nothing published" from "could not
	 * read", and an empty string would erase a previously resolved version.
	 *
	 * This page still carries three infos-module values (author, two dates), so a
	 * parser that simply took the first one would return a date here.
	 *
	 * @return void
	 */
	public function testProductWithoutVersionYieldsNull()
	{
		$this->assertNull(
			$this->callPrivate('parseProductVersion', array($this->fixture('dolistore_product_noversion.html')))
		);
	}

	/**
	 * Dates share the info box with the version and must never be mistaken for
	 * one, whichever order the rows come in.
	 *
	 * @return void
	 */
	public function testDatesAreNotVersions()
	{
		$html = '<html><body><ul class="info-list-box">'
			.'<li><b>Release date</b> <span class="infos-module">01/25/2023</span></li>'
			.'<li><b>Last update</b> <span class="infos-module">07/27/2026 09:37 AM</span></li>'
			.'<li><b>Module version</b> <span class="infos-module">3.4.1</span></li>'
			.'</ul></body></html>';

		$this->assertSame('3.4.1', $this->callPrivate('parseProductVersion', array($html)));
	}

	/**
	 * Suffixed versions are real on DoliStore (betas, release candidates).
	 *
	 * @return void
	 */
	public function testSuffixedVersionIsAccepted()
	{
		$html = '<html><body><ul class="info-list-box">'
			.'<li><b>Module version</b> <span class="infos-module">2.1.0-beta</span></li>'
			.'</ul></body></html>';

		$this->assertSame('2.1.0-beta', $this->callPrivate('parseProductVersion', array($html)));
	}

	/**
	 * A page that is not a product page (redirect body, error page, front-end
	 * redesign) resolves to null rather than to junk.
	 *
	 * @return void
	 */
	public function testUnrelatedMarkupYieldsNull()
	{
		$this->assertNull($this->callPrivate('parseProductVersion', array('<html><body>Not found</body></html>')));
	}

	/**
	 * A bare version with no minor part is not a version here: single integers
	 * appear in the box as counts and years, and DoliStore versions always carry
	 * at least one dot.
	 *
	 * @return void
	 */
	public function testBareIntegerIsNotAVersion()
	{
		$html = '<html><body><ul class="info-list-box">'
			.'<li><b>Downloads</b> <span class="infos-module">2023</span></li>'
			.'</ul></body></html>';

		$this->assertNull($this->callPrivate('parseProductVersion', array($html)));
	}

	/**
	 * An id that cannot name a product short-circuits before any request.
	 *
	 * @return void
	 */
	public function testInvalidIdResolvesToNullWithoutRequest()
	{
		$this->assertNull($this->client->fetchProductVersion(0));
		$this->assertNull($this->client->fetchProductVersion(-5));
	}
}
