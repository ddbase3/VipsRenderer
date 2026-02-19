<?php declare(strict_types=1);

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
