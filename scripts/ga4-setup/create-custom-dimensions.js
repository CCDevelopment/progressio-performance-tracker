/**
 * Register Progressio Performance Tracker event parameters as GA4
 * event-scoped custom dimensions, so they can be queried via the GA4 Data API.
 *
 * This is a one-off setup utility — it is NOT part of the plugin runtime.
 *
 * ── Prerequisites ──────────────────────────────────────────────────────────
 * 1. A Google Cloud service account with the Google Analytics Admin API enabled.
 *    Download its JSON key file.
 * 2. Add that service account's email as a user on the GA4 property with at
 *    least the "Editor" role (Admin → Property Access Management).
 * 3. Your GA4 numeric Property ID (Admin → Property Settings → "Property ID",
 *    e.g. 123456789 — NOT the "G-XXXX" measurement ID).
 *
 * ── Usage ──────────────────────────────────────────────────────────────────
 *   npm install
 *   GA4_PROPERTY_ID=123456789 \
 *   GOOGLE_APPLICATION_CREDENTIALS=/absolute/path/to/key.json \
 *   node create-custom-dimensions.js
 *
 * The script is idempotent: it lists existing custom dimensions first and
 * skips any parameter that is already registered, so it is safe to re-run.
 */

import { AnalyticsAdminServiceClient } from '@google-analytics/admin';

/* Event parameters sent by the plugin. parameterName must match exactly what
   tracker.js sends; displayName is the human-readable label shown in GA4. */
const DIMENSIONS = [
	{ parameterName: 'cta_tier',         displayName: 'CTA Tier' },
	{ parameterName: 'cta_label',        displayName: 'CTA Label' },
	{ parameterName: 'button_text',      displayName: 'Button Text' },
	{ parameterName: 'button_class',     displayName: 'Button Class' },
	{ parameterName: 'link_url',         displayName: 'Link URL' },
	{ parameterName: 'link_text',        displayName: 'Link Text' },
	{ parameterName: 'form_id',          displayName: 'Form ID' },
	{ parameterName: 'form_title',       displayName: 'Form Title' },
	{ parameterName: 'form_plugin',      displayName: 'Form Plugin' },
	{ parameterName: 'percent_scrolled', displayName: 'Percent Scrolled' },
	{ parameterName: 'phone_number',     displayName: 'Phone Number' },
	{ parameterName: 'traffic_source',   displayName: 'Traffic Source' },
	{ parameterName: 'traffic_medium',   displayName: 'Traffic Medium' },
	{ parameterName: 'traffic_campaign', displayName: 'Traffic Campaign' },
	{ parameterName: 'traffic_keyword',  displayName: 'Traffic Keyword' },
	{ parameterName: 'traffic_content',  displayName: 'Traffic Content' },
	{ parameterName: 'gclid',            displayName: 'Google Click ID' },
	{ parameterName: 'fbclid',           displayName: 'Facebook Click ID' },
];

async function main() {
	const propertyId = process.env.GA4_PROPERTY_ID;
	if ( ! propertyId || ! /^\d+$/.test( propertyId ) ) {
		console.error( 'ERROR: set GA4_PROPERTY_ID to your numeric GA4 property ID (e.g. 123456789).' );
		process.exit( 1 );
	}
	if ( ! process.env.GOOGLE_APPLICATION_CREDENTIALS ) {
		console.error( 'ERROR: set GOOGLE_APPLICATION_CREDENTIALS to the path of your service account JSON key.' );
		process.exit( 1 );
	}

	const client = new AnalyticsAdminServiceClient();
	const parent = `properties/${ propertyId }`;

	// ── Fetch existing custom dimensions so we skip duplicates. ──
	const existing = new Set();
	for await ( const dim of client.listCustomDimensionsAsync( { parent } ) ) {
		existing.add( dim.parameterName );
	}
	console.log( `Found ${ existing.size } existing custom dimension(s) on ${ parent }.` );

	const toCreate = DIMENSIONS.filter( ( d ) => ! existing.has( d.parameterName ) );
	if ( toCreate.length === 0 ) {
		console.log( 'All parameters are already registered. Nothing to do.' );
		return;
	}

	// ── GA4 free tier allows 50 event-scoped custom dimensions. ──
	if ( existing.size + toCreate.length > 50 ) {
		console.warn(
			`WARNING: this would exceed the 50 custom-dimension limit ` +
			`(${ existing.size } existing + ${ toCreate.length } new). Some may fail.`
		);
	}

	let created = 0;
	let failed = 0;
	for ( const dim of toCreate ) {
		try {
			await client.createCustomDimension( {
				parent,
				customDimension: {
					parameterName: dim.parameterName,
					displayName: dim.displayName,
					scope: 'EVENT',
				},
			} );
			console.log( `  ✓ created  ${ dim.parameterName }  (${ dim.displayName })` );
			created++;
		} catch ( err ) {
			console.error( `  ✗ failed   ${ dim.parameterName }: ${ err.message }` );
			failed++;
		}
	}

	console.log( `\nDone. Created ${ created }, skipped ${ existing.size }, failed ${ failed }.` );
	console.log( 'Note: newly created dimensions take ~24-48h before they return data via the Data API.' );
}

main().catch( ( err ) => {
	console.error( 'Fatal:', err.message );
	process.exit( 1 );
} );
