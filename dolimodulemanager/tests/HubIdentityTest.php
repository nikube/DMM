<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/HubIdentityTest.php
 * \ingroup dolimodulemanager
 * \brief   Regression test for hub URL canonicalization / dedup.
 *
 * Bug (DMM <= 1.11.0): the same dmmhub.json referenced by different URL forms
 * (raw.githubusercontent.com vs api.github.com/contents vs github.com/blob) was
 * stored as several distinct hubs, showing duplicate rows in the Sources tab.
 * dmm_hub_identity() maps every form to one identity; dmm_save_hubs() dedupes.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

require_once __DIR__.'/../lib/dolimodulemanager.lib.php';

/** Minimal DoliDB double so dmm_save_hubs()'s dmm_set_setting() call is harmless. */
class HubIdentityFakeDB
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
}

final class HubIdentityTest extends TestCase
{
	public function testGithubFormsShareOneIdentity(): void
	{
		$raw = dmm_hub_identity('https://raw.githubusercontent.com/nikube/DMMHub/master/dmmhub.json');
		$api = dmm_hub_identity('https://api.github.com/repos/nikube/DMMHub/contents/dmmhub.json');
		$blob = dmm_hub_identity('https://github.com/nikube/DMMHub/blob/master/dmmhub.json');

		$this->assertSame('github:nikube/dmmhub', $raw);
		$this->assertSame($raw, $api, 'raw and api forms must share identity');
		$this->assertSame($raw, $blob, 'blob form must share identity');
	}

	public function testDistinctReposAreDistinct(): void
	{
		$a = dmm_hub_identity('https://api.github.com/repos/nikube/DMMHub/contents/dmmhub.json');
		$b = dmm_hub_identity('https://api.github.com/repos/nikube/DMMHubPrivate/contents/dmmhub.json');
		$this->assertNotSame($a, $b);
	}

	public function testCaseInsensitiveOwnerRepo(): void
	{
		$lower = dmm_hub_identity('https://api.github.com/repos/nikube/dmmhub/contents/dmmhub.json');
		$mixed = dmm_hub_identity('https://api.github.com/repos/NiKube/DMMHub/contents/dmmhub.json');
		$this->assertSame($lower, $mixed);
	}

	public function testGitSuffixStripped(): void
	{
		$plain = dmm_hub_identity('https://github.com/nikube/DMMHub');
		$dotgit = dmm_hub_identity('https://github.com/nikube/DMMHub.git');
		$this->assertSame($plain, $dotgit);
	}

	public function testNonGithubFallsBackToNormalisedUrl(): void
	{
		$a = dmm_hub_identity('https://example.com/myhub/dmmhub.json');
		$b = dmm_hub_identity('http://example.com/myhub/dmmhub.json/'); // scheme + trailing slash
		$this->assertSame($a, $b, 'scheme and trailing slash must be normalised away');
		$this->assertSame('example.com/myhub/dmmhub.json', $a);
	}

	protected function setUp(): void
	{
		$GLOBALS['db'] = new HubIdentityFakeDB();
	}

	public function testSaveHubsDedupesByIdentity(): void
	{
		$saved = dmm_save_hubs(array(
			array('url' => 'https://raw.githubusercontent.com/nikube/DMMHub/master/dmmhub.json', 'enabled' => 1),
			array('url' => 'https://api.github.com/repos/nikube/DMMHub/contents/dmmhub.json', 'enabled' => 0),
			array('url' => 'https://api.github.com/repos/nikube/DMMHubPrivate/contents/dmmhub.json', 'enabled' => 0),
		));
		$this->assertCount(2, $saved, 'the two DMMHub forms collapse to one, plus the private hub');
	}

	public function testSaveHubsKeepsEnabledWhenAnyDuplicateEnabled(): void
	{
		// First (disabled) form seen first, a later duplicate is enabled → result enabled.
		$saved = dmm_save_hubs(array(
			array('url' => 'https://api.github.com/repos/nikube/DMMHub/contents/dmmhub.json', 'enabled' => 0),
			array('url' => 'https://raw.githubusercontent.com/nikube/DMMHub/master/dmmhub.json', 'enabled' => 1),
		));
		$this->assertCount(1, $saved);
		$this->assertSame(1, $saved[0]['enabled'], 'an enabled duplicate must win over a disabled one');
	}
}
