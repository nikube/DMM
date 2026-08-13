<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/CommunityVersionTest.php
 * \ingroup dolimodulemanager
 * \brief   Regression test for version reporting on community-index modules.
 *
 * Bug (DMM <= 2.1.0): a community module reported a commit SHA as its version.
 * The shared repository cuts no releases, so checkUpdate() fell back to branch
 * HEAD and wrote "dev:{sha12}" over every version field — even though the index
 * publishes current_version and the module on disk declares the same semver.
 *
 * Two consequences, both seen on einvoicing (published 1.0.3):
 *   - the card and dashboard showed "dev:b720d7e3ce8d" instead of 1.0.3
 *   - a SHA never equals a semver, so every check claimed an update was
 *     available and the install button never settled
 *
 * The index heal was silently broken too: importFromCommunityYaml() assigned
 * cache_latest_version on the row, but update()'s SET list has no cache_*
 * column — only updateCache() writes those — so the published version was
 * dropped on the floor every refresh.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

require_once __DIR__.'/../class/DMMClient.class.php';

/** DoliDB double: the constructor probes for the DMM tables before anything else. */
class CommunityVersionFakeDB
{
	public function prefix()
	{
		return 'llx_';
	}
	public function escape($s)
	{
		return $s;
	}
	public function query($sql)
	{
		return true;
	}
	public function num_rows($r)
	{
		return 0;
	}
	public function free($r = null)
	{
	}
}

final class CommunityVersionTest extends TestCase
{
	/**
	 * Reach the private version-selection helper directly: it is the decision the
	 * bug turned on, and driving it through checkUpdate() would need the network.
	 *
	 * @param  object|null $row Registry row double
	 * @param  array       $map module_id => published version
	 * @return string|null
	 */
	private function published($row, array $map)
	{
		$client = new DMMClient(new CommunityVersionFakeDB(), false);

		$ref = new ReflectionClass($client);
		$memo = $ref->getProperty('communityVersions');
		$memo->setValue($client, $map);

		$method = $ref->getMethod('publishedBranchVersion');

		return $method->invoke($client, $row);
	}

	/** A row shaped like the registry's, with only the fields the helper reads. */
	private function row($source, $moduleId)
	{
		return (object) array('source' => $source, 'module_id' => $moduleId);
	}

	public function testCommunityRowUsesThePublishedVersion(): void
	{
		$version = $this->published(
			$this->row('dolibarr-community', 'einvoicing'),
			array('einvoicing' => '1.0.3')
		);

		$this->assertSame('1.0.3', $version);
	}

	/**
	 * The whole point: what gets reported must be the version, never the commit
	 * the branch install happened to land on.
	 */
	public function testPublishedVersionIsNeverAShaPrefix(): void
	{
		$version = $this->published(
			$this->row('dolibarr-community', 'einvoicing'),
			array('einvoicing' => '1.0.3')
		);

		$this->assertStringStartsNotWith('dev:', (string) $version);
	}

	/**
	 * A module on the dev channel by user preference is asking to track commits,
	 * so it must keep comparing SHAs. Only community rows are branch-backed by
	 * distribution rather than by choice.
	 */
	public function testNonCommunitySourceKeepsShaComparison(): void
	{
		$this->assertNull($this->published(
			$this->row('hub', 'somemodule'),
			array('somemodule' => '2.0.0')
		));

		$this->assertNull($this->published(
			$this->row('token', 'othermodule'),
			array('othermodule' => '2.0.0')
		));
	}

	/** An index that lists the module without a version says nothing to use. */
	public function testModuleAbsentFromIndexFallsBack(): void
	{
		$this->assertNull($this->published(
			$this->row('dolibarr-community', 'einvoicing'),
			array('helloasso' => '2.1.1')
		));
	}

	public function testNoRowFallsBack(): void
	{
		$this->assertNull($this->published(null, array('einvoicing' => '1.0.3')));
	}

	/**
	 * The heal writes through updateCache(), because update() does not carry the
	 * cache_* columns. Guard that split: if update() ever grows a cache_latest_*
	 * assignment, or updateCache() loses one, the heal silently stops working —
	 * which is exactly how the published version went missing for a release.
	 */
	public function testCacheColumnsAreOwnedByUpdateCacheOnly(): void
	{
		$source = file_get_contents(__DIR__.'/../class/DMMModule.class.php');
		$this->assertNotFalse($source);

		$updateBody = $this->methodBody($source, 'update');
		$this->assertNotSame('', $updateBody, 'update() not found');
		$this->assertStringNotContainsString(
			'cache_latest_version =',
			$updateBody,
			'update() must not write cache columns — callers rely on updateCache() for them'
		);

		$updateCacheBody = $this->methodBody($source, 'updateCache');
		$this->assertStringContainsString('cache_latest_version =', $updateCacheBody);
		$this->assertStringContainsString('cache_latest_compatible =', $updateCacheBody);
	}

	/**
	 * Crude brace matcher, enough to isolate one method from the class source.
	 *
	 * @param  string $source Full PHP source
	 * @param  string $name   Method name
	 * @return string         Body, or '' when not found
	 */
	private function methodBody($source, $name)
	{
		$start = strpos($source, 'function '.$name.'(');
		if ($start === false) {
			return '';
		}
		$open = strpos($source, '{', $start);
		if ($open === false) {
			return '';
		}

		$depth = 0;
		for ($i = $open, $len = strlen($source); $i < $len; $i++) {
			if ($source[$i] === '{') {
				$depth++;
			} elseif ($source[$i] === '}') {
				$depth--;
				if ($depth === 0) {
					return substr($source, $open, $i - $open + 1);
				}
			}
		}

		return '';
	}
}
