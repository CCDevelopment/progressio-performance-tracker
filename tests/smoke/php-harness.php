<?php
// Runtime smoke test for the plugin classes with WordPress stubbed out.
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( ! function_exists( 'str_starts_with' ) ) { function str_starts_with( $h, $n ) { return 0 === strncmp( $h, $n, strlen( $n ) ); } }
if ( ! function_exists( 'str_contains' ) ) { function str_contains( $h, $n ) { return '' === $n || false !== strpos( $h, $n ); } }

define( 'ABSPATH', '/tmp/' );
$GLOBALS['options'] = array();
$GLOBALS['actions'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['http']    = array();
$GLOBALS['http_response'] = array( 'code' => 200, 'body' => '{"id":"lead_123"}' );
$GLOBALS['settings_errors'] = array();

function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.com/wp-content/plugins/ppt/'; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['actions'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['filters'][ $h ][] = $cb; }
function apply_filters( $h, $v ) { return $v; }
function do_action( $h, ...$args ) { foreach ( $GLOBALS['actions'][ $h ] ?? array() as $cb ) { call_user_func_array( $cb, $args ); } }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_clear_scheduled_hook( $h ) {}
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_single_event( $t, $h ) { $GLOBALS['scheduled'][] = $t; return true; }
function __( $s, $d = null ) { return $s; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_js( $s ) { return $s; }
function sanitize_text_field( $s ) { $s = strip_tags( (string) $s ); return trim( preg_replace( '/[\r\n\t ]+/', ' ', $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return preg_replace( '/[^a-zA-Z0-9.@_+\-]/', '', (string) $s ); }
function is_email( $s ) { return (bool) filter_var( (string) $s, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://www.example.com' . $p; }
function wp_get_referer() { return $_SERVER['HTTP_REFERER'] ?? false; }
function esc_url_raw( $u ) { return filter_var( $u, FILTER_VALIDATE_URL ) ? $u : ''; }
function wp_http_validate_url( $u ) { return filter_var( $u, FILTER_VALIDATE_URL ) ? $u : false; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_uuid4() { return 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'; }
function wp_salt( $s = '' ) { return 'salt'; }
function is_ssl() { return true; }
function add_settings_error( $s, $c, $m ) { $GLOBALS['settings_errors'][] = $m; }
function get_settings_errors( $s ) { return array_map( fn( $m ) => array( 'message' => $m ), $GLOBALS['settings_errors'] ); }
function wp_safe_remote_post( $url, $args ) {
	$GLOBALS['http'][] = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => $GLOBALS['http_response']['code'], 'message' => 'x' ), 'body' => $GLOBALS['http_response']['body'] );
}
function is_wp_error( $t ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_response_message( $r ) { return $r['response']['message']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function human_time_diff( $t ) { return '1 min'; }

$root = dirname( __DIR__, 1 );
$plugin = getenv( "PPT_ROOT" ) ?: dirname( __DIR__, 2 );
require $plugin . '/progressio-performance-tracker.php';

$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) $fail++; }

// ── Attribution classification ──────────────────────────────────────────────
$c = fn( $t ) => PPT_Attribution::classify( $t );
ok( 'Paid Search'    === $c( array( 'gclid' => 'x' ) ), 'gclid → Paid Search' );
ok( 'Paid Social'    === $c( array( 'fbclid' => 'x' ) ), 'fbclid → Paid Social' );
ok( 'Paid Social'    === $c( array( 'source' => 'facebook', 'medium' => 'cpc' ) ), 'facebook/cpc → Paid Social' );
ok( 'Paid Search'    === $c( array( 'source' => 'google', 'medium' => 'cpc' ) ), 'google/cpc → Paid Search' );
ok( 'Organic Search' === $c( array( 'source' => 'google.com', 'medium' => 'referral', 'referrer' => 'google.com' ) ), 'google referrer → Organic Search' );
ok( 'Organic Search' === $c( array( 'source' => 'google.co.uk', 'medium' => 'referral', 'referrer' => 'google.co.uk' ) ), 'google.co.uk referrer → Organic Search' );
ok( 'Organic Social' === $c( array( 'source' => 't.co', 'medium' => 'referral', 'referrer' => 't.co' ) ), 't.co referrer → Organic Social' );
ok( 'Organic Social' === $c( array( 'source' => 'l.facebook.com', 'medium' => 'referral', 'referrer' => 'l.facebook.com' ) ), 'l.facebook.com → Organic Social' );
ok( 'Referral'       === $c( array( 'source' => 'partner.example.org', 'medium' => 'referral', 'referrer' => 'partner.example.org' ) ), 'other referrer → Referral' );
ok( 'Email'          === $c( array( 'source' => 'mailchimp', 'medium' => 'email' ) ), 'email → Email' );
ok( 'Direct'         === $c( array() ), 'empty → Direct' );
ok( 'Display'        === $c( array( 'dclid' => 'x' ) ), 'dclid → Display' );

// ── Cookie sanitisation ─────────────────────────────────────────────────────
$_COOKIE['ppt_ft'] = rawurlencode( json_encode( array(
	's'  => '<script>alert(1)</script>google',
	'm'  => array( 'nested' ),
	'c'  => str_repeat( 'A', 500 ),
	'lp' => 'https://evil.com/page?token=SECRET&utm_source=g&password=x',
	'ch' => 'Totally Fake Channel',
	'ts' => 'not-a-number',
	'v'  => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
	'g'  => 'CjX',
) ) );
$t = PPT_Attribution::read_touch( 'ppt_ft' );
ok( 'alert(1)google' === $t['source'], 'script tags stripped from source: ' . $t['source'] );
ok( ! isset( $t['medium'] ), 'array medium dropped' );
ok( 200 === strlen( $t['campaign'] ), 'campaign capped to 200' );
ok( '/page?utm_source=g' === $t['landing_page'], 'landing page keeps only marketing params: ' . $t['landing_page'] );
ok( 'Paid Search' === $t['channel'], 'client channel replaced by server classification: ' . $t['channel'] );
ok( ! isset( $t['timestamp'] ), 'non-numeric timestamp dropped' );
ok( 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' === $t['visitor_id'], 'visitor id kept' );

$_COOKIE['ppt_lt'] = 'not json at all';
ok( array() === PPT_Attribution::read_touch( 'ppt_lt' ), 'garbage cookie → empty' );
$_COOKIE['ppt_lt'] = rawurlencode( str_repeat( '{', 5000 ) );
ok( array() === PPT_Attribution::read_touch( 'ppt_lt' ), 'oversize cookie → empty' );

$_SERVER['HTTP_REFERER'] = 'https://example.com/contact/?session=abc&utm_campaign=spring';
ok( 'https://www.example.com/contact/?utm_campaign=spring' === PPT_Attribution::submitting_page_url(), 'submitting page from referer (www-insensitive, query filtered): ' . PPT_Attribution::submitting_page_url() );
$_SERVER['HTTP_REFERER'] = 'https://attacker.com/contact/';
ok( null === PPT_Attribution::submitting_page_url(), 'foreign referer rejected' );

// ── Settings validation ─────────────────────────────────────────────────────
ok( PPT_Settings::is_valid_endpoint( 'https://dashboard.progressiodev.com/api/leads' ), 'default endpoint valid' );
ok( ! PPT_Settings::is_valid_endpoint( 'http://dashboard.progressiodev.com/api/leads' ), 'http endpoint rejected' );
ok( ! PPT_Settings::is_valid_endpoint( 'https://evil.com/api/leads' ), 'foreign host rejected' );
ok( ! PPT_Settings::is_valid_endpoint( 'https://169.254.169.254/' ), 'metadata IP rejected' );
ok( PPT_Settings::is_valid_measurement_id( 'G-ABC123XYZ' ) && ! PPT_Settings::is_valid_measurement_id( 'UA-12345-1' ) && ! PPT_Settings::is_valid_measurement_id( 'G-<x>' ), 'measurement id format' );

$GLOBALS['options']['ppt_settings'] = array_merge( ppt_default_options(), array( 'leads_api_key' => 'existing-key-1234', 'measurement_id' => 'G-OLD' ) );
$settings = PPT_Settings::get_instance();
$clean = $settings->sanitize_options( array(
	'measurement_id' => 'not valid',
	'leads_endpoint' => 'https://evil.com/x',
	'leads_api_key'  => '',
	'form_plugin'    => 'hacked',
	'attribution_days' => '9999',
	'cta_primary_class' => '<b>btn</b> btn--go',
	'field_map' => array(
		array( 'plugin' => 'cf7', 'form_id' => '12', 'email' => 'your-email', 'name' => 'your-name' ),
		array( 'plugin' => 'any', 'form_id' => '' ), // empty → dropped
		'garbage',
	),
) );
ok( 'G-OLD' === $clean['measurement_id'], 'invalid MID keeps previous' );
ok( PPT_DEFAULT_ENDPOINT === $clean['leads_endpoint'], 'invalid endpoint keeps previous/default' );
ok( 'existing-key-1234' === $clean['leads_api_key'], 'blank key keeps existing' );
ok( 'auto' === $clean['form_plugin'], 'unknown form plugin → auto' );
ok( 365 === $clean['attribution_days'], 'attribution days clamped' );
ok( 'btn btn--go' === $clean['cta_primary_class'], 'cta class sanitized: ' . $clean['cta_primary_class'] );
ok( 1 === count( $clean['field_map'] ) && 'your-email' === $clean['field_map'][0]['email'], 'field map: empty/garbage rows dropped' );
ok( count( $GLOBALS['settings_errors'] ) === 2, 'two settings errors raised: ' . count( $GLOBALS['settings_errors'] ) );
$GLOBALS['settings_errors'] = array();
$clean = $settings->sanitize_options( array( 'leads_api_key_clear' => '1' ) );
ok( '' === $clean['leads_api_key'], 'clear checkbox removes key' );
$clean = $settings->sanitize_options( 'not an array' );
ok( is_array( $clean ), 'non-array input does not fatal' );

// ── Lead extraction ─────────────────────────────────────────────────────────
$GLOBALS['options']['ppt_settings'] = array_merge( ppt_default_options(), array(
	'leads_api_key' => 'test-key-abcdef',
	'field_map'     => array( array( 'plugin' => 'cf7', 'form_id' => '99', 'name' => 'your-name|your-surname', 'email' => 'your-email' ) ),
) );
// Re-instantiate settings/leads with the new option (singletons hold state — use reflection).
foreach ( array( 'PPT_Settings', 'PPT_Leads' ) as $cls ) { $r = new ReflectionProperty( $cls, 'instance' ); $r->setAccessible( true ); $r->setValue( null, null ); }
$leads = PPT_Leads::get_instance();
ok( $leads->is_configured(), 'leads configured' );

$f = fn( $id, $label, $type, $value ) => array( 'id' => $id, 'label' => $label, 'type' => $type, 'value' => $value );
$x = $leads->extract_contact( array(
	$f( '1', 'Company Name', 'text', 'Acme Ltd' ),
	$f( '2', 'Full Name', 'text', 'Jane Doe' ),
	$f( '3', 'Hotel Preference', 'text', '5 star' ),
	$f( '4', 'Email', 'email', 'jane@example.com' ),
	$f( '5', 'Mobile', 'text', '+44 7700 900123' ),
	$f( '6', 'How can we help?', 'textarea', "Need a <b>quote</b>\nplease" ),
), 'wsform', '1' );
ok( 'Jane Doe' === $x['name'], 'name skips Company Name: ' . $x['name'] );
ok( 'jane@example.com' === $x['email'], 'email by type' );
ok( '+44 7700 900123' === $x['phone'], 'phone by label, hotel ignored: ' . ( $x['phone'] ?? '' ) );
ok( "Need a quote\nplease" === $x['message'], 'message from textarea, tags stripped' );

$x = $leads->extract_contact( array(
	$f( '3.3', 'Name First', 'name', 'John' ),
	$f( '3.6', 'Name Last', 'name', 'Smith' ),
	$f( '3', 'Name', 'name', 'John Smith' ),
	$f( '4', 'Phone', 'phone', '555' ),           // too short → rejected
	$f( '5', 'Your Email', 'text', 'not-an-email' ),
	$f( '6', 'Comments', 'text', 'hi john@smith.com' ), // textarea-ish fallback shouldn't yield email (contains other text)
), 'gravityforms', '7' );
ok( 'John Smith' === $x['name'], 'GF full name preferred: ' . $x['name'] );
ok( ! isset( $x['phone'] ), 'short phone rejected' );
ok( ! isset( $x['email'] ), 'invalid email rejected' );

$x = $leads->extract_contact( array(
	$f( 'your-name', 'your-name', 'text', 'Ann' ),
	$f( 'your-surname', 'your-surname', 'text', 'Lee' ),
	$f( 'your-email', 'your-email', 'email', 'ann@lee.io' ),
	$f( 'your-tel', 'your-tel', 'tel', '0123456789' ),
), 'cf7', '99' );
ok( 'Ann Lee' === $x['name'], 'mapping joins name parts: ' . $x['name'] );
ok( 'ann@lee.io' === $x['email'] && '0123456789' === $x['phone'], 'mapping email + heuristic phone (tel type)' );

$x = $leads->extract_contact( array( $f( 'name', '', 'text', 'Only Name' ) ), 'elementor', 'x' );
ok( ! isset( $x['email'] ) && 'Only Name' === $x['name'], 'elementor id fallback' );

// ── Lead dispatch via CF7 hook + payload shape ──────────────────────────────
class WPCF7_Submission { public static function get_instance() { return new self(); } public function get_posted_data() { return array( 'your-name' => 'Bob', 'your-email' => 'bob@example.com', 'topics' => array( 'a', 'b' ) ); } }
class FakeCF7 { public function id() { return 5; } public function title() { return 'Contact'; } public function scan_form_tags() { return array( (object) array( 'name' => 'your-email', 'basetype' => 'email' ) ); } }
$_COOKIE['ppt_ft'] = rawurlencode( json_encode( array( 's' => 'google', 'm' => 'cpc', 'g' => 'CjX', 'lp' => '/landing?gclid=CjX', 'ts' => 1700000000, 'v' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' ) ) );
$_COOKIE['ppt_lt'] = rawurlencode( json_encode( array( 'lp' => '/pricing', 'ts' => 1700090000, 'ch' => 'Direct' ) ) );
$_SERVER['HTTP_REFERER'] = 'https://www.example.com/contact/';
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$_SERVER['HTTP_USER_AGENT'] = 'TestUA';
do_action( 'wpcf7_mail_sent', new FakeCF7() );
ok( 1 === count( $GLOBALS['http'] ), 'one HTTP request sent' );
$req = $GLOBALS['http'][0];
$p = json_decode( $req['args']['body'], true );
ok( 'https://dashboard.progressiodev.com/api/leads' === $req['url'], 'sent to default endpoint' );
ok( 'Bearer test-key-abcdef' === $req['args']['headers']['Authorization'], 'bearer header present' );
ok( 'bob@example.com' === $p['email'] && 'Bob' === $p['name'], 'contact in payload' );
ok( 'google' === $p['source'] && 'cpc' === $p['medium'] && 'Paid Search' === $p['channel'], 'last non-direct: falls back to first touch when session is direct: ' . json_encode( array( $p['source'] ?? null, $p['channel'] ?? null ) ) );
ok( 'https://www.example.com/landing?gclid=CjX' === $p['landingPage'], 'landingPage = first touch: ' . $p['landingPage'] );
ok( 'https://www.example.com/pricing' === $p['sessionLandingPage'], 'sessionLandingPage = last touch' );
ok( 'https://www.example.com/contact/' === $p['pageUrl'], 'pageUrl from referer' );
ok( 'Direct' === $p['lastTouch']['channel'] && 'Paid Search' === $p['firstTouch']['channel'], 'nested touches' );
ok( 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' === $p['visitorId'] && 64 === strlen( $p['contactHash'] ) && 32 === strlen( $p['ipHash'] ), 'ids/hashes' );
ok( false === $p['isSpam'] && 'cf7' === $p['formPlugin'] && 'Contact' === $p['formTitle'] && '5' === $p['formId'], 'form meta' );
ok( ! array_key_exists( 'phone', $p ) && ! array_key_exists( 'message', $p ), 'empty fields omitted' );
$status = $leads->get_status();
ok( 1 === $status['total_sent'] && 'lead_123' === $status['last_lead_id'], 'status recorded' );

// ── Failure → queue → retry ─────────────────────────────────────────────────
$GLOBALS['http'] = array();
$GLOBALS['http_response'] = array( 'code' => 503, 'body' => 'down' );
do_action( 'wpcf7_mail_sent', new FakeCF7() );
ok( 1 === count( $leads->get_queue() ), 'failed lead queued' );
ok( ! empty( $GLOBALS['scheduled'] ), 'retry scheduled' );
$GLOBALS['http_response'] = array( 'code' => 400, 'body' => 'bad' );
do_action( 'wpcf7_mail_sent', new FakeCF7() );
ok( 1 === count( $leads->get_queue() ), '4xx not queued' );
$GLOBALS['http_response'] = array( 'code' => 201, 'body' => '{"data":{"leadId":"L9"}}' );
$stats = $leads->process_queue( true );
ok( 1 === $stats['sent'] && 0 === $stats['remaining'] && array() === $leads->get_queue(), 'queue drained on retry: ' . json_encode( $stats ) );
ok( 'L9' === $leads->get_status()['last_lead_id'], 'nested lead id parsed' );

// ── Test lead ───────────────────────────────────────────────────────────────
$GLOBALS['http'] = array();
$r = $leads->send_test( 'me@example.com' );
$p = json_decode( $GLOBALS['http'][0]['args']['body'], true );
ok( $r['ok'] && true === $p['test'] && 'me@example.com' === $p['email'], 'test lead flagged' );

echo "\n" . ( $fail ? "$fail FAILURE(S)" : 'ALL PASSED' ) . "\n";
exit( $fail ? 1 : 0 );
