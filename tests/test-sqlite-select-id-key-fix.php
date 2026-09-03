<?php
/**
 * Minimal regression tests for the conservative SELECT key-case rewriter.
 */

define( 'ABSPATH', __DIR__ );

function add_filter() {}
function add_action() {}

require dirname( __DIR__ ) . '/plugins/sqlite-select-id-key-fix.php';

/**
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    Assertion label.
 * @return void
 */
function assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$label}\nExpected: " . var_export( $expected, true ) . "\nActual:   " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$rewrite_cases = array(
	'one qualified column' => array(
		'SELECT P.id FROM wp_posts AS P',
		'SELECT P.id AS "id" FROM wp_posts AS P',
	),
	'multiple columns preserve written case' => array(
		'SELECT P.ID, P.post_title FROM wp_posts AS P WHERE P.ID = 1',
		'SELECT P.ID AS "ID", P.post_title AS "post_title" FROM wp_posts AS P WHERE P.ID = 1',
	),
	'existing alias remains intact' => array(
		'SELECT P.ID AS post_id, P.post_title FROM wp_posts AS P',
		'SELECT P.ID AS post_id, P.post_title AS "post_title" FROM wp_posts AS P',
	),
	'bare column' => array(
		'SELECT id FROM wp_posts',
		'SELECT id AS "id" FROM wp_posts',
	),
	'join is refused' => array(
		'SELECT P.id FROM wp_posts P JOIN wp_users U ON U.ID = P.post_author',
		null,
	),
	'distinct is refused' => array(
		'SELECT DISTINCT P.id FROM wp_posts P',
		null,
	),
	'wildcard is refused' => array(
		'SELECT P.* FROM wp_posts P',
		null,
	),
	'function is refused' => array(
		'SELECT MAX(P.id) FROM wp_posts P',
		null,
	),
	'union is refused' => array(
		'SELECT P.id FROM wp_posts P UNION SELECT U.id FROM wp_users U',
		null,
	),
	'non-select is unchanged' => array(
		'UPDATE wp_posts SET post_title = "test"',
		null,
	),
);

foreach ( $rewrite_cases as $label => $case ) {
	list( $input, $expected ) = $case;
	if ( null === $expected ) {
		$expected = $input;
	}
	assert_same( $expected, sqlite_select_id_key_fix_rewrite_query( $input ), $label );
}

$renamed_arrays = sqlite_select_id_key_fix_apply_rename(
	array( array( 'ID' => 7, 'post_title' => 'Hello' ) ),
	array( 'id' => 'id' )
);
assert_same( array( array( 'id' => 7, 'post_title' => 'Hello' ) ), $renamed_arrays, 'ARRAY_A key rename' );

$row             = new stdClass();
$row->ID         = 9;
$row->post_title = 'World';
$renamed_objects = sqlite_select_id_key_fix_apply_rename(
	array( $row ),
	array( 'id' => 'id' )
);
assert_same( 9, $renamed_objects[0]->id, 'OBJECT property rename' );
assert_same( 'World', $renamed_objects[0]->post_title, 'OBJECT unrelated property' );

fwrite( STDOUT, "sqlite-select-id-key-fix tests passed\n" );
