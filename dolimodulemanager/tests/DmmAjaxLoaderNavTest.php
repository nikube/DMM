<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/DmmAjaxLoaderNavTest.php
 * \ingroup dolimodulemanager
 * \brief   The loader overlay has two modes: JSON fetch (data-dmm-ajax) and
 *          plain navigation with a Cancel button (data-dmm-nav), used by slow
 *          pages such as the "Hubs / tokens" catalog tab. Pins the helper
 *          output and the overlay markup the inline script relies on.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

// Stubs for the few Dolibarr helpers the loader touches, so this runs in CI
// without a Dolibarr tree. Inside dolitest the real ones are already loaded.
if (!function_exists('dol_escape_htmltag')) {
	function dol_escape_htmltag($s)
	{
		return htmlspecialchars((string) $s, ENT_QUOTES);
	}
}
if (!function_exists('dol_escape_js')) {
	function dol_escape_js($s)
	{
		return addslashes((string) $s);
	}
}
if (!class_exists('DmmAjaxLoaderFakeLangs')) {
	class DmmAjaxLoaderFakeLangs
	{
		public function load($f)
		{
		}
		public function trans($k)
		{
			return $k;
		}
	}
}

require_once __DIR__.'/../lib/dolimodulemanager.lib.php';

final class DmmAjaxLoaderNavTest extends TestCase
{
	public function testAttrsDefaultToAjaxMode(): void
	{
		$attrs = dmm_ajax_attrs('Check');
		$this->assertStringContainsString('data-dmm-ajax="1"', $attrs);
		$this->assertStringNotContainsString('data-dmm-nav', $attrs);
		$this->assertStringContainsString('data-dmm-ajax-label="Check"', $attrs);
	}

	public function testNavigateModeEmitsNavAttrOnly(): void
	{
		$attrs = dmm_ajax_attrs('Hubs / "jetons"', true);
		$this->assertStringContainsString('data-dmm-nav="1"', $attrs);
		$this->assertStringNotContainsString('data-dmm-ajax="1"', $attrs);
		// Label is escaped, never raw.
		$this->assertStringNotContainsString('"jetons"', $attrs);
		$this->assertStringContainsString('data-dmm-ajax-label="Hubs / &quot;jetons&quot;"', $attrs);
	}

	public function testOverlayAssetsCarryCancelAndNavHandler(): void
	{
		global $langs;
		$saved = $langs;
		if (!is_object($langs)) {
			$langs = new DmmAjaxLoaderFakeLangs();
		}
		ob_start();
		dmm_print_ajax_loader_assets();
		dmm_print_ajax_loader_assets(); // printed once per page only
		$html = ob_get_clean();
		$langs = $saved;

		$this->assertSame(1, substr_count($html, 'id="dmmAjaxOverlay"'));
		$this->assertSame(1, substr_count($html, 'id="dmmAjaxCancel"'));
		// The click handler must catch both link flavours and call window.stop() on cancel.
		$this->assertStringContainsString('a[data-dmm-ajax=\"1\"],a[data-dmm-nav=\"1\"]', $html);
		$this->assertStringContainsString('window.stop()', $html);
		$this->assertStringContainsString('data-dmm-nav") === "1"', $html);
	}
}
