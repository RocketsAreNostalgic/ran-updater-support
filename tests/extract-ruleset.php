<?php
/**
 * Return the same XML selector metadata used by the Node enforcement contract.
 *
 * @package RanUpdaterSupport
 */

declare(strict_types=1);

$ran_updater_support_source = stream_get_contents( STDIN );
if ( false === $ran_updater_support_source ) {
	throw new RuntimeException( 'Cannot read the ruleset input.' );
}
$ran_updater_support_xml = simplexml_load_string( $ran_updater_support_source );
if ( false === $ran_updater_support_xml ) {
	exit( 1 );
}
/** @return array<SimpleXMLElement> Matched elements from the native XPath producer. */
$ran_updater_support_query      = static function ( string $expression ) use ( $ran_updater_support_xml ): array {
	$nodes = $ran_updater_support_xml->xpath( $expression );
	if ( ! is_array( $nodes ) ) {
		throw new RuntimeException( 'Cannot inspect ruleset XPath metadata.' );
	}
	return $nodes;
};
$ran_updater_support_values     = static fn( $nodes ) => array_map( static fn( $node ) => (string) $node, $nodes );
$ran_updater_support_attributes = static fn( $nodes ) => array_map( static fn( $node ) => (array) $node->attributes(), $nodes );
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.Security.EscapeOutput.OutputNotEscaped -- Preserve the native JSON_THROW_ON_ERROR/stdout protocol consumed by the Node harness; no WordPress runtime or HTML rendering applies.
echo json_encode(
	array(
		'forbidden'     => count( $ran_updater_support_query( '//@phpcs-only | //@phpcbf-only | //include-pattern | //rule//exclude-pattern | //exclude | //severity | //type | //file/@* | //exclude-pattern/@* | //rule/@*[name() != "ref"]' ) ),
		'files'         => $ran_updater_support_values( $ran_updater_support_query( '//file' ) ),
		'exclusions'    => $ran_updater_support_values( $ran_updater_support_query( '//exclude-pattern' ) ),
		'rules'         => $ran_updater_support_values( $ran_updater_support_query( '//rule/@ref' ) ),
		'arguments'     => $ran_updater_support_attributes( $ran_updater_support_query( '//arg' ) ),
		'configuration' => $ran_updater_support_attributes( $ran_updater_support_query( '//config' ) ),
	),
	JSON_THROW_ON_ERROR
);
