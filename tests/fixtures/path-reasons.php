<?php

declare(strict_types=1);

return array(
	'archive_paths'    => array(
		'path_empty'                      => array( '' ),
		'path_too_long'                   => array( str_repeat( 'a/', 2048 ) . 'a' ),
		'path_absolute'                   => array( '/plugin/main.php' ),
		'path_control_character'          => array( "plugin/\x00main.php", "plugin/\nmain.php", "plugin/\x7fmain.php" ),
		'path_unsafe_character'           => array( "plugin/\xc3\xa9.php", "plugin/\x80.php", 'plugin\\main.php', 'C:/plugin', 'a<b', 'a|b', 'a*b', 'a?b', 'a"b' ),
		'path_empty_component'            => array( 'plugin//main.php', 'plugin//', 'plugin///x/' ),
		'path_dot_segment'                => array( './plugin', 'plugin/../main.php', 'plugin/./main.php', 'plugin/..', '..' ),
		'component_too_long'              => array( 'plugin/' . str_repeat( 'a', 256 ) ),
		'component_trailing_dot_or_space' => array( 'plugin/main.', 'plugin/main ', 'plugin./main' ),
		'component_reserved_name'         => array( 'plugin/con', 'plugin/NUL.txt', 'plugin/Aux.tar.gz', 'plugin/com1', 'plugin/lpt9.log' ),
	),
	'entry_types'      => array(
		// Each case is origin, attributes, directory.
		'entry_metadata_unavailable' => array( array( null, 0, false ), array( 3, null, false ), array( null, null, true ) ),
		'entry_type_mismatch'        => array( array( 3, 0100644 << 16, true ), array( 3, 0040755 << 16, false ), array( 0, 0x10, false ), array( 0, 0x20, true ) ),
		'entry_type_special'         => array( array( 3, 0120777 << 16, false ), array( 3, 0020666 << 16, false ), array( 3, 0060660 << 16, false ) ),
		'entry_origin_unsupported'   => array( array( 1, 0, false ), array( 10, 0x10, true ), array( 255, 0, false ) ),
		'entry_volume_label'         => array( array( 0, 0x08, false ), array( 0, 0x18, true ) ),
	),
	'repository_paths' => array(
		'path_control_character'         => array( "packages/plug\0in", "\tpackages", "packages\n" ),
		'path_empty'                     => array( '', '   ' ),
		'path_absolute'                  => array( '/packages' ),
		'path_backslash'                 => array( 'packages\\plugin' ),
		'path_drive_letter'              => array( 'C:/packages', 'z:' ),
		'path_empty_component'           => array( 'packages//plugin', 'packages/plugin//' ),
		'path_dot_segment'               => array( 'packages/./plugin', '..', 'packages/../plugin' ),
		'path_encoded_traversal'         => array( 'packages/%2e%2e', 'packages/%252e', 'packages%2fplugin', 'packages%5cplugin', '%2e' ),
		'path_encoded_drive_letter'      => array( 'C%3A/packages', '%43:/packages', 'C%253A/packages' ),
		'path_encoded_control_character' => array( 'packages/plug%00in', 'packages/plug%2500in', 'packages/%0a' ),
		'path_decode_limit'              => array( 'packages/%' . str_repeat( '25', 8 ) . '41' ),
	),
);
