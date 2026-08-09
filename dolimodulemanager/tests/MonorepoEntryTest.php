<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/MonorepoEntryTest.php
 * \ingroup dolimodulemanager
 * \brief   Regression tests for registering one module out of a monorepo.
 *
 * parseGitUrl() already read the subdir and branch out of a
 * /tree/{branch}/{subdir} URL, but the two "add a repo" handlers then threw both
 * away, and each failure surfaced far from its cause:
 *
 *  - The id came from the repo name, so every module of DoliCloud/DoliMods
 *    wanted to install as "dolimods". Dolibarr resolves a module's own includes
 *    through its custom/{module_id} folder, so the module listed fine and
 *    fatalled on first use.
 *  - The branch was dropped and the row stayed on the stable channel, so the
 *    install hunted for tags a monorepo never cuts and died on a bare
 *    "Download failed with HTTP 404".
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

require_once __DIR__.'/../lib/dolimodulemanager.lib.php';

/** Stand-in for the DMMModule row the add handlers populate. */
class MonorepoFakeModuleRow
{
	public $module_id;
	public $subdir;
	public $branch;
	public $branch_dev;
	public $channel = 'stable';
	public $source = '';
}

final class MonorepoEntryTest extends TestCase
{
	/**
	 * The module is named by its own directory, not by the repo that carries it.
	 *
	 * @return void
	 */
	public function testSubdirNamesTheModule()
	{
		$parsed = array(
			'repo' => 'DoliMods',
			'subdir' => 'htdocs/stancerdolicloud',
			'branch' => 'master',
		);

		$this->assertSame('stancerdolicloud', dmm_module_id_from_parsed($parsed));
	}

	/**
	 * A plain repo keeps naming itself — the pre-existing behaviour.
	 *
	 * @return void
	 */
	public function testPlainRepoKeepsRepoName()
	{
		$parsed = array('repo' => 'crmplus', 'subdir' => null, 'branch' => null);

		$this->assertSame('crmplus', dmm_module_id_from_parsed($parsed));
	}

	/**
	 * Repo names carry separators that a module id may not; they are stripped,
	 * as the inline expressions this helper replaced already did.
	 *
	 * @return void
	 */
	public function testSeparatorsAreStripped()
	{
		$parsed = array('repo' => 'my-module.v2', 'subdir' => null, 'branch' => null);

		$this->assertSame('mymodulev2', dmm_module_id_from_parsed($parsed));
	}

	/**
	 * A dmm.json that declares its own id wins over both — that is the module
	 * telling us its name rather than us guessing it from a path.
	 *
	 * @return void
	 */
	public function testManifestIdWins()
	{
		$parsed = array(
			'repo' => 'DoliMods',
			'subdir' => 'htdocs/stancerdolicloud',
			'branch' => 'master',
		);

		$this->assertSame('declaredid', dmm_module_id_from_parsed($parsed, 'declaredid'));
	}

	/**
	 * A manifest id that cannot be a folder name is not trusted blindly; the
	 * path-derived id is used instead of writing an unusable directory.
	 *
	 * @return void
	 */
	public function testInvalidManifestIdFallsBackToPath()
	{
		$parsed = array(
			'repo' => 'DoliMods',
			'subdir' => 'htdocs/stancerdolicloud',
			'branch' => 'master',
		);

		$this->assertSame('stancerdolicloud', dmm_module_id_from_parsed($parsed, '../../etc'));
	}

	/**
	 * A trailing slash on the subdir must not produce an empty id.
	 *
	 * @return void
	 */
	public function testTrailingSlashOnSubdir()
	{
		$parsed = array('repo' => 'DoliMods', 'subdir' => 'htdocs/concatpdf/', 'branch' => 'master');

		$this->assertSame('concatpdf', dmm_module_id_from_parsed($parsed));
	}

	/**
	 * A /tree/{branch}/ URL points at a branch, so the row must track it.
	 *
	 * @return void
	 */
	public function testBranchFromUrlSwitchesRowToDev()
	{
		$mod = new MonorepoFakeModuleRow();

		dmm_apply_parsed_branch($mod, array('branch' => 'master'));

		$this->assertSame('master', $mod->branch);
		$this->assertSame('master', $mod->branch_dev);
		$this->assertSame('dev', $mod->channel);
	}

	/**
	 * No branch in the URL leaves the row alone: a plain repo is tag-tracked.
	 *
	 * @return void
	 */
	public function testNoBranchLeavesChannelStable()
	{
		$mod = new MonorepoFakeModuleRow();

		dmm_apply_parsed_branch($mod, array('branch' => null));

		$this->assertNull($mod->branch);
		$this->assertSame('stable', $mod->channel);
	}

	/**
	 * A monorepo row follows its branch whether or not dev mode is on: there is
	 * no per-module tag to fall back to, so requiring the dev-mode preference
	 * would send the install looking for releases that do not exist.
	 *
	 * @return void
	 */
	public function testMonorepoRowTracksBranchWithoutDevMode()
	{
		$mod = new MonorepoFakeModuleRow();
		$mod->subdir = 'htdocs/stancerdolicloud';
		$mod->branch_dev = 'master';
		$mod->channel = 'dev';

		$this->assertTrue(dmm_module_tracks_branch($mod));
	}
}
