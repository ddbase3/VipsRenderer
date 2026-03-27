<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of VipsRenderer for BASE3 Framework.
 *
 * VipsRenderer extends the BASE3 framework with a libvips-based image
 * rendering backend for file-based image transformations and exports.
 * It provides fast rendering for publish-ready image derivatives.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/vipsrenderer
 * https://github.com/ddbase3/VipsRenderer
 **********************************************************************/

namespace VipsRenderer;

use Base3\Api\IContainer;
use Base3\Api\IPlugin;

class VipsRendererPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	// Implementation of IBase

	public static function getName(): string {
		return 'vipsrendererplugin';
	}

	// Implementation of IPlugin

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED);
	}
}
