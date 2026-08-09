<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/CommunityParsingTest.php
 * \ingroup dolimodulemanager
 * \brief   Regression tests for the two pure parsers behind community imports.
 *
 * Both had defects that only showed up as wrong data much later, far from the
 * cause:
 *
 *  - parseGitUrl() read the branch out of a /tree/{branch}/{subdir} URL and then
 *    dropped it, so a monorepo subdirectory could be extracted from a branch
 *    other than the one the URL named.
 *  - parseCommunityYaml() could not tell `key:` (opens a nested mapping) from
 *    `key: ""` (an empty string). The real community index contains
 *    `phpmax: ""`, which became an array — and an array assigned to a varchar
 *    column. The fallback parser only runs when ext-yaml is missing, i.e. on
 *    shared hosting, which is exactly where this was hardest to notice.
 */

use PHPUnit\Framework\TestCase;

final class CommunityParsingTest extends TestCase
{
	/** @var DMMClient */
	private $client;

	protected function setUp(): void
	{
		require_once __DIR__.'/../class/DMMClient.class.php';
		$this->client = new DMMClient(new CommunityParsingFakeDB());
	}

	/**
	 * Call a private method under test.
	 *
	 * @param  string $name Method name
	 * @param  array  $args Arguments
	 * @return mixed
	 */
	private function call($name, array $args)
	{
		$m = new ReflectionMethod('DMMClient', $name);
		$m->setAccessible(true);
		return $m->invokeArgs($this->client, $args);
	}

	public function testTreeUrlYieldsBranchAndSubdir()
	{
		$p = $this->call('parseGitUrl', array(
			'https://github.com/Dolibarr/dolibarr-community-modules/tree/main/einvoicing',
		));

		$this->assertSame('github', $p['host']);
		$this->assertSame('Dolibarr/dolibarr-community-modules', $p['project']);
		$this->assertSame('main', $p['branch']);
		$this->assertSame('einvoicing', $p['subdir']);
	}

	public function testPlainUrlHasNoBranchOrSubdir()
	{
		$p = $this->call('parseGitUrl', array('https://github.com/owner/repo'));

		$this->assertSame('owner/repo', $p['project']);
		$this->assertNull($p['branch']);
		$this->assertNull($p['subdir']);
	}

	/**
	 * Self-hosted GitLab with a nested namespace and a non-main default branch —
	 * the case that motivated not guessing "main"/"master".
	 */
	public function testGitlabTreeUrlKeepsNamespaceAndBranch()
	{
		$p = $this->call('parseGitUrl', array(
			'https://git.open-dsi.fr/group/sub/proj/tree/2026/modules/foo',
		));

		$this->assertSame('gitlab', $p['host']);
		$this->assertSame('group/sub/proj', $p['project']);
		$this->assertSame('2026', $p['branch']);
		$this->assertSame('modules/foo', $p['subdir']);
	}

	public function testTrailingDotGitIsStripped()
	{
		$p = $this->call('parseGitUrl', array('https://github.com/owner/repo.git'));

		$this->assertSame('owner/repo', $p['project']);
		$this->assertSame('repo', $p['repo']);
	}

	/**
	 * The bug: `phpmax: ""` is an empty string, not a nested mapping.
	 */
	public function testQuotedEmptyScalarStaysAString()
	{
		$yaml = "packages:\n"
			."  - modulename: 'EInvoicing'\n"
			."    git: 'https://github.com/Dolibarr/dolibarr-community-modules'\n"
			."    phpmax: \"\"\n"
			."    dolistore-download: ''\n"
			."    git-branch: 'main'\n";

		$entries = $this->call('parseCommunityYaml', array($yaml));
		$this->assertCount(1, $entries);
		$e = $entries[0];

		$this->assertSame('', $e['phpmax'], 'phpmax: "" must stay an empty string');
		$this->assertSame('', $e['dolistore-download'], "dolistore-download: '' must stay an empty string");
		$this->assertSame('main', $e['git-branch'], 'a field after a quoted empty must still parse');
	}

	/**
	 * A bare `key:` still opens a nested mapping — the behaviour the fix must
	 * not break, since label/description rely on it.
	 */
	public function testBareKeyStillOpensNestedMapping()
	{
		$yaml = "packages:\n"
			."  - modulename: 'Foo'\n"
			."    label:\n"
			."      en: 'Foo module'\n"
			."      fr: 'Module Foo'\n"
			."    git: 'https://github.com/owner/repo'\n";

		$entries = $this->call('parseCommunityYaml', array($yaml));
		$this->assertCount(1, $entries);

		$this->assertIsArray($entries[0]['label']);
		$this->assertSame('Foo module', $entries[0]['label']['en']);
		$this->assertSame('Module Foo', $entries[0]['label']['fr']);
		$this->assertSame('https://github.com/owner/repo', $entries[0]['git']);
	}

	public function testMultipleEntriesAreSeparated()
	{
		$yaml = "packages:\n"
			."  - modulename: 'One'\n"
			."    git: 'https://github.com/owner/one'\n"
			."  - modulename: 'Two'\n"
			."    git: 'https://github.com/owner/two'\n";

		$entries = $this->call('parseCommunityYaml', array($yaml));

		$this->assertCount(2, $entries);
		$this->assertSame('One', $entries[0]['modulename']);
		$this->assertSame('Two', $entries[1]['modulename']);
	}
}

/**
 * Minimal DoliDB stand-in: these parsers never touch the database, but the
 * DMMClient constructor wants one.
 */
class CommunityParsingFakeDB
{
	public $type = 'mysqli';

	public function prefix()
	{
		return 'llx_';
	}

	public function escape($s)
	{
		return addslashes((string) $s);
	}

	public function query($sql)
	{
		return false;
	}

	public function num_rows($r)
	{
		return 0;
	}

	public function fetch_object($r)
	{
		return null;
	}

	public function affected_rows($r)
	{
		return 0;
	}
}
