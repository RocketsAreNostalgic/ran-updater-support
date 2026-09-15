<?php

declare(strict_types=1);

return array(
	'valid'   => array(
		'single segment'     => array( 'plugin', 'plugin' ),
		'nested path'        => array( 'packages/plugin', 'packages/plugin' ),
		'outer whitespace'   => array( '  packages/plugin  ', 'packages/plugin' ),
		'trailing separator' => array( 'packages/plugin/', 'packages/plugin' ),
		'percent literal'    => array( 'packages/percent%25name', 'packages/percent%25name' ),
		'encoded colon'      => array( 'packages/C%3A-name', 'packages/C%3A-name' ),
	),
	'invalid' => array(
		'empty'                     => '',
		'whitespace only'           => " \t\n ",
		'absolute path'             => '/packages/plugin',
		'windows separator'         => 'packages\\plugin',
		'windows drive path'        => 'C:/packages/plugin',
		'control character'         => "packages/plug\0in",
		'trailing control'          => "packages/plugin\n",
		'leading control'           => "\tpackages/plugin",
		'empty segment'             => 'packages//plugin',
		'dot segment'               => 'packages/./plugin',
		'dot-dot segment'           => 'packages/../plugin',
		'encoded dot-dot'           => 'packages/%2e%2e/plugin',
		'double encoded dot-dot'    => 'packages/%252e%252e/plugin',
		'encoded slash'             => 'packages%2fplugin',
		'double encoded slash'      => 'packages%252fplugin',
		'encoded backslash'         => 'packages%5cplugin',
		'double encoded backslash'  => 'packages%255cplugin',
		'encoded control'           => 'packages/plug%00in',
		'double encoded control'    => 'packages/plug%2500in',
		'double trailing separator' => 'packages/plugin//',
	),
);
