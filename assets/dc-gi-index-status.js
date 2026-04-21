/* DC Google Indexing — Index Status Tab
   Depends on: dcGiPoll (localized via wp_localize_script), jQuery
*/
/* global jQuery, dcGiPoll */
(function () {
	'use strict';

	// Guard: only run on the Index Status tab.
	if ( ! document.getElementById( 'dc-gi-is-url-tbody' ) ) { return; }

	var nonce          = dcGiPoll.nonce;
	var ajaxUrl        = dcGiPoll.ajaxurl;
	var i18n           = dcGiPoll.i18n;
	var isPage         = 1;
	var isFilter       = '';
	var isTotalPages   = 1;
	var isOrderBy      = 'last_crawl_time';
	var isOrder        = 'DESC';
	var isSearch       = '';
	var isSearchTimer  = null;
	var isCoverageFilter = '';
	var isAllExpanded  = false;

	// Verdict → display badge HTML.
	var verdictBadge = {
		'PASS':                '<span style="color:#00f2c3;font-weight:600">\u2713 Indexed</span>',
		'NEUTRAL':             '<span style="color:#ff8d72;font-weight:600">\u26a0 Excluded</span>',
		'VERDICT_UNSPECIFIED': '<span style="color:#9b5fe0;font-weight:600">? Unknown</span>',
		'FAIL':                '<span style="color:#fd5d93;font-weight:600">\u2717 Fail</span>'
	};

	function esc( s ) {
		return ( s || '' ).replace( /&/g,'&amp;' ).replace( /</g,'&lt;' ).replace( />/g,'&gt;' ).replace( /"/g,'&quot;' );
	}

	function fmtDate( s ) {
		if ( ! s || '0000-00-00 00:00:00' === s ) { return '\u2014'; }
		var d = new Date( s.replace( ' ', 'T' ) + 'Z' );
		return isNaN( d ) ? s : d.toLocaleDateString( undefined, { year:'numeric', month:'short', day:'numeric' } );
	}

	function fmtState( s ) {
		if ( ! s ) { return '\u2014'; }
		return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase().replace( /_/g, ' ' );
	}

	// ── Sort icon update ─────────────────────────────────────────────────────

	function updateSortIcons() {
		document.querySelectorAll( '.dc-gi-sort-icon' ).forEach( function ( el ) {
			var col = el.getAttribute( 'data-col' );
			if ( col === isOrderBy ) {
				el.textContent = 'ASC' === isOrder ? ' \u2191' : ' \u2193';
			} else {
				el.textContent = col ? ' \u2195' : '';
			}
		} );
	}

	// ── CSV href sync ────────────────────────────────────────────────────────

	function updateCsvHref() {
		var link = document.getElementById( 'dc-gi-is-export-csv' );
		if ( ! link ) { return; }
		var base = link.getAttribute( 'data-base-href' ) || '';
		link.href = base
			+ '&filter='          + encodeURIComponent( isFilter )
			+ '&search='          + encodeURIComponent( isSearch )
			+ '&coverage_filter=' + encodeURIComponent( isCoverageFilter )
			+ '&order_by='        + encodeURIComponent( isOrderBy )
			+ '&order='           + encodeURIComponent( isOrder );
	}

	// ── Load URL table page ──────────────────────────────────────────────────

	function loadUrlTable( page, filter, orderBy, order ) {
		isPage   = page;
		isFilter = filter;
		if ( orderBy ) { isOrderBy = orderBy; }
		if ( order   ) { isOrder   = order; }
		updateCsvHref();
		var tbody = document.getElementById( 'dc-gi-is-url-tbody' );
		if ( tbody ) { tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#7a8499;padding:24px">' + esc( i18n.isLoading ) + '</td></tr>'; }
		updateSortIcons();
		jQuery.post( ajaxUrl, {
			action:          'dc_gi_is_urls',
			nonce:           nonce,
			page:            page,
			filter:          filter,
			order_by:        isOrderBy,
			order:           isOrder,
			search:          isSearch,
			coverage_filter: isCoverageFilter
		}, function ( r ) {
			if ( ! r || ! r.success ) { return; }
			var d = r.data;
			isTotalPages = d.total_pages || 1;
			renderUrlTable( d.rows, d.page, d.total );
		} );
	}

	// ── Render rows with expandable detail panel ─────────────────────────────

	function buildRichResults( jsonStr ) {
		if ( ! jsonStr ) { return ''; }
		var items;
		try { items = JSON.parse( jsonStr ); } catch(e) { return ''; }
		if ( ! items || ! items.length ) { return ''; }
		var html = '';
		items.forEach( function( rtype ) {
			var hasError   = false;
			var hasWarning = false;
			var flatIssues = [];
			( rtype.i || [] ).forEach( function( item ) {
				( item.i || [] ).forEach( function( iss ) {
					if ( 'ERROR'   === iss.s ) { hasError   = true; }
					if ( 'WARNING' === iss.s ) { hasWarning = true; }
					flatIssues.push( { m: iss.m, s: iss.s, n: item.n } );
				} );
			} );
			var validCount = ( rtype.i || [] ).filter( function( item ) {
				return ! ( item.i || [] ).some( function( iss ) { return 'ERROR' === iss.s; } );
			} ).length;
			var iconColor = hasError ? '#fd5d93' : '#00f2c3';
			var icon      = hasError ? '\u2717' : '\u2713';
			html += '<div style="display:flex;align-items:flex-start;gap:8px;padding:5px 0;border-bottom:1px solid rgba(45,53,85,.3)">'
				+ '<span style="color:' + iconColor + ';font-weight:700;font-size:13px;line-height:1.4">' + icon + '</span>'
				+ '<div style="flex:1">'
				+ '<span style="color:#c8d0e0;font-weight:600;font-size:12px">' + esc( rtype.t || '' ) + '</span>';
			if ( validCount > 0 ) {
				html += ' <span style="color:#7a8499;font-size:11px">\u2014 ' + validCount + ' valid</span>';
			}
			if ( hasWarning && ! hasError ) {
				html += ' <span style="color:#ff8d72;font-size:11px">\u26a0 non-critical issues</span>';
			}
			flatIssues.forEach( function( iss ) {
				var col = 'ERROR' === iss.s ? '#fd5d93' : '#ff8d72';
				html += '<div style="font-size:11px;color:' + col + ';margin-top:3px">\u26a0 ';
				if ( iss.n ) { html += '<span style="color:#7a8499">' + esc( iss.n ) + ':</span> '; }
				html += esc( iss.m ) + '</div>';
			} );
			html += '</div></div>';
		} );
		return html;
	}

	function buildDetailRow( row, offset, i, extra ) {
		var gc  = row.google_canonical || '';
		var uc  = row.user_canonical   || '';
		var rts = row.robots_txt_state || '';
		var idx = row.indexing_state   || '';
		var rr  = buildRichResults( row.rich_results || '' );

		var sHead = function( t, first ) {
			return '<div style="font-size:10px;font-weight:700;color:#7a8499;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px'
				+ ( first ? '' : ';border-top:1px solid rgba(45,53,85,.6);margin-top:12px;padding-top:10px' )
				+ '">' + t + '</div>';
		};
		var yesNo = function( ok ) {
			return ok
				? '<span style="color:#00f2c3;font-weight:600">\u2713 ' + esc( i18n.isYes ) + '</span>'
				: '<span style="color:#fd5d93;font-weight:600">\u2717 ' + esc( i18n.isNo  ) + '</span>';
		};
		var grid2 = '<div style="display:grid;grid-template-columns:minmax(160px,auto) 1fr;gap:5px 16px;align-items:baseline">';

		var html = '<tr class="dc-gi-is-detail-row" data-idx="' + (offset+i) + '" style="display:none">'
			+ '<td colspan="8" style="background:#111827;padding:0 0 0 48px;border-top:none">'
			+ '<div style="padding:12px 16px 12px 0;font-size:12px;max-width:900px">';

		// ── Coverage ─────────────────────────────────────────────────────────────
		if ( row.coverage_state ) {
			html += sHead( esc( i18n.isCoverage ), true );
			html += '<div style="color:#c8d0e0">' + esc( row.coverage_state ) + '</div>';
		}

		// ── Crawl ─────────────────────────────────────────────────────────────────
		if ( row.last_crawl_time || row.crawled_as || rts || row.page_fetch_state || idx ) {
			html += sHead( esc( i18n.isCrawl ), ! row.coverage_state );
			html += grid2;
			if ( row.last_crawl_time ) {
				html += '<span style="color:#7a8499">' + esc( i18n.isCrawlTime ) + ':</span>'
					+ '<span style="color:#c8d0e0">' + esc( fmtDate( row.last_crawl_time ) ) + '</span>';
			}
			if ( row.crawled_as ) {
				html += '<span style="color:#7a8499">' + esc( i18n.isCrawledAs ) + ':</span>'
					+ '<span style="color:#c8d0e0">' + esc( fmtState( row.crawled_as ) ) + '</span>';
			}
			if ( rts ) {
				html += '<span style="color:#7a8499">' + esc( i18n.isCrawlAllowed ) + ':</span>'
					+ yesNo( 'ALLOWED' === rts );
			}
			if ( row.page_fetch_state ) {
				var pfOk = 'SUCCESSFUL' === row.page_fetch_state;
				html += '<span style="color:#7a8499">' + esc( i18n.isPageFetch ) + ':</span>'
					+ ( pfOk
						? '<span style="color:#00f2c3;font-weight:600">\u2713 ' + esc( fmtState( row.page_fetch_state ) ) + '</span>'
						: '<span style="color:#fd5d93">' + esc( fmtState( row.page_fetch_state ) ) + '</span>' );
			}
			if ( idx ) {
				html += '<span style="color:#7a8499">' + esc( i18n.isIndexingAllowed ) + ':</span>'
					+ yesNo( 'INDEXING_ALLOWED' === idx );
			}
			html += '</div>';
		}

		// ── Indexing ──────────────────────────────────────────────────────────────
		if ( gc || uc ) {
			html += sHead( esc( i18n.isIndexing ) );
			html += grid2;
			if ( uc ) {
				html += '<span style="color:#7a8499;white-space:nowrap">' + esc( i18n.isUserCanonical ) + ':</span>'
					+ '<a href="' + esc( uc ) + '" target="_blank" rel="noopener noreferrer" style="color:#6ab0f5;font-size:11px;word-break:break-all">' + esc( uc ) + '</a>';
			}
			html += '<span style="color:#7a8499;white-space:nowrap">' + esc( i18n.isGoogleCanonical ) + ':</span>';
			if ( gc ) {
				var gcMatch = gc === row.url;
				html += '<a href="' + esc( gc ) + '" target="_blank" rel="noopener noreferrer" style="color:' + ( gcMatch ? '#00f2c3' : '#ff8d72' ) + ';font-size:11px;word-break:break-all">' + esc( gc ) + '</a>'
					+ ( ! gcMatch ? ' <span style="color:#ff8d72;font-size:11px">(' + esc( i18n.isDiffersFromUserCanonical ) + ')</span>' : '' );
			} else {
				html += '<span style="color:#7a8499;font-style:italic;font-size:11px">' + esc( i18n.isCanonicalPending ) + '</span>';
			}
			html += '</div>';
		}

		// ── Last submitted ────────────────────────────────────────────────────────
		if ( row.last_submitted && '0000-00-00 00:00:00' !== row.last_submitted ) {
			html += '<div style="margin-top:8px"><span style="color:#7a8499">' + esc( i18n.isLastSubmitted ) + ':</span> <span data-is-submitted style="color:#c8d0e0">' + esc( fmtDate( row.last_submitted ) ) + '</span></div>';
		} else {
			html += '<div style="margin-top:8px"><span style="color:#7a8499">' + esc( i18n.isLastSubmitted ) + ':</span> <span data-is-submitted style="color:#c8d0e0">\u2014</span></div>';
		}

		// ── Rich Results ──────────────────────────────────────────────────────────
		if ( rr ) {
			html += sHead( esc( i18n.isRichResults ) );
			html += rr;
		}

		// ── Search Analytics ──────────────────────────────────────────────────────
		if ( row.sa_updated ) {
			var ctr    = row.sa_ctr         != null ? ( parseFloat( row.sa_ctr )         * 100 ).toFixed( 1 ) + '%' : '\u2014';
			var pos    = row.sa_position    != null ? parseFloat( row.sa_position    ).toFixed( 1 )                  : '\u2014';
			var clicks = row.sa_clicks      != null ? parseInt( row.sa_clicks,      10 )                             : '\u2014';
			var impr   = row.sa_impressions != null ? parseInt( row.sa_impressions,  10 )                            : '\u2014';
			html += sHead( esc( i18n.isSearchAnalytics ) );
			html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">'
				+ '<div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px;text-align:center"><div style="font-size:18px;font-weight:700;color:#1d8cf8">' + esc( String( clicks ) ) + '</div><div style="font-size:10px;color:#7a8499;margin-top:3px;text-transform:uppercase">' + esc( i18n.isClicks ) + '</div></div>'
				+ '<div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px;text-align:center"><div style="font-size:18px;font-weight:700;color:#c8d0e0">' + esc( String( impr ) ) + '</div><div style="font-size:10px;color:#7a8499;margin-top:3px;text-transform:uppercase">' + esc( i18n.isImpressions ) + '</div></div>'
				+ '<div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px;text-align:center"><div style="font-size:18px;font-weight:700;color:#00f2c3">' + esc( ctr ) + '</div><div style="font-size:10px;color:#7a8499;margin-top:3px;text-transform:uppercase">' + esc( i18n.isCtr ) + '</div></div>'
				+ '<div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px;text-align:center"><div style="font-size:18px;font-weight:700;color:#ff8d72">' + esc( pos ) + '</div><div style="font-size:10px;color:#7a8499;margin-top:3px;text-transform:uppercase">' + esc( i18n.isAvgPosition ) + '</div></div>'
				+ '</div>'
				+ '<div style="font-size:10px;color:#7a8499;margin-top:6px">' + esc( i18n.isUpdated ) + ' ' + esc( row.sa_updated ) + ' UTC</div>';
		}

		// ── Live inspect extras (present only after an on-demand Inspect call) ──
		if ( extra ) {
			// Mobile Usability.
			var mv = extra.mobile && extra.mobile.verdict ? extra.mobile.verdict : '';
			if ( mv && 'VERDICT_UNSPECIFIED' !== mv ) {
				html += sHead( esc( i18n.isMobileUsability ) );
				html += grid2
					+ '<span style="color:#7a8499">' + esc( i18n.isMobileUsability ) + ':</span>'
					+ yesNo( 'PASS' === mv )
					+ '</div>';
				( extra.mobile.issues || [] ).forEach( function( iss ) {
					var col = 'ERROR' === iss.severity ? '#fd5d93' : '#ff8d72';
					html += '<div style="font-size:11px;color:' + col + ';margin-top:4px">\u26a0 ' + esc( iss.message || iss.type ) + '</div>';
				} );
			}

			// AMP.
			var av = extra.amp && extra.amp.verdict ? extra.amp.verdict : '';
			if ( av && 'VERDICT_UNSPECIFIED' !== av ) {
				var avBadge = 'PASS' === av
					? '<span style="color:#00f2c3;font-weight:600">\u2713 Pass</span>'
					: 'FAIL' === av
						? '<span style="color:#fd5d93;font-weight:600">\u2717 Fail</span>'
						: '<span style="color:#ff8d72;font-weight:600">\u26a0 ' + esc( fmtState( av ) ) + '</span>';
				html += sHead( esc( i18n.isAmp ) );
				html += '<div>' + avBadge;
				if ( extra.amp.url ) {
					html += ' <a href="' + esc( extra.amp.url ) + '" target="_blank" rel="noopener noreferrer" style="color:#6ab0f5;font-size:11px">' + esc( extra.amp.url ) + '</a>';
				}
				html += '</div>';
				( extra.amp.issues || [] ).forEach( function( iss ) {
					var col = 'ERROR' === iss.severity ? '#fd5d93' : '#ff8d72';
					html += '<div style="font-size:11px;color:' + col + ';margin-top:4px">\u26a0 ' + esc( iss.message ) + '</div>';
				} );
			}

			// Sitemaps.
			if ( extra.sitemap && extra.sitemap.length ) {
				html += '<div style="margin-top:6px"><span style="color:#7a8499">' + esc( i18n.isSitemaps ) + ':</span> <span style="color:#c8d0e0;font-size:11px">'
					+ extra.sitemap.map( function( s ) { return esc( s ); } ).join( ', ' )
					+ '</span></div>';
			}

			// View in Search Console.
			if ( extra.inspect_link ) {
				html += '<div style="margin-top:8px">'
					+ '<a href="' + esc( extra.inspect_link ) + '" target="_blank" rel="noopener noreferrer" style="color:#6ab0f5;font-size:12px">'
					+ esc( i18n.isViewInGsc ) + '</a></div>';
			}
		}

		// ── Buttons ───────────────────────────────────────────────────────────────
		html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">'
			+ '<button type="button" class="button button-small dc-gi-is-inspect-detail-btn" data-url="' + esc( row.url ) + '">' + esc( i18n.isInspect ) + '</button>';
		if ( 'PASS' !== row.index_verdict ) {
			html += '<button type="button" class="button button-small dc-gi-is-resubmit-btn" data-url="' + esc( row.url ) + '">' + esc( i18n.isResubmit ) + '</button>';
		}
		html += '</div>';

		html += '</div></td></tr>';
		return html;
	}

	// ── Shared re-submit button wiring ───────────────────────────────────────
	// Called both by renderUrlTable (for all buttons after a full table render)
	// and by the inspect-now handler (for the single button in a replaced detail row).

	function wireResubmitBtn( btn, tbody ) {		btn.addEventListener( 'click', function( e ) {
			e.preventDefault();
			e.stopPropagation();
			var url = btn.getAttribute( 'data-url' ) || '';
			if ( ! url ) { return; }
			btn.disabled    = true;
			btn.textContent = i18n.isQueueing;
			jQuery.post( ajaxUrl, {
				action: 'dc_gi_watch_resubmit_one',
				nonce:  nonce,
				url:    url
			}, function( resp ) {
				if ( ! resp || ! resp.success ) {
					window.alert( i18n.isResubmitError );
					btn.disabled    = false;
					btn.textContent = i18n.isResubmit;
					return;
				}
				btn.textContent = i18n.isQueued;

				// Update queue badge.
				var queueCount = resp.data && resp.data.queue_count ? resp.data.queue_count : null;
				if ( null !== queueCount ) {
					var headerQueue = document.getElementById( 'dc-gi-header-queue' );
					if ( headerQueue ) { headerQueue.textContent = queueCount; }
					var bodyQueue = document.getElementById( 'dc-gi-queue-body-count' );
					if ( bodyQueue ) { bodyQueue.textContent = queueCount; }
				}

				// Find the parent data row via the shared data-idx on the detail row.
				var detailRow = btn.closest( '.dc-gi-is-detail-row' );
				var idx       = detailRow ? detailRow.getAttribute( 'data-idx' ) : null;
				var dataRow   = idx ? tbody.querySelector( '.dc-gi-is-data-row[data-idx="' + idx + '"]' ) : null;

				// Update verdict badge → "Submitted" in the data row.
				if ( dataRow ) {
					var verdictCell = dataRow.querySelector( 'td:nth-child(3)' );
					if ( verdictCell ) {
						verdictCell.innerHTML = '<span style="color:#1d8cf8;font-weight:600">\u21BB Submitted</span>';
					}
					// Flash the row (same technique as Watchlist).
					dataRow.classList.remove( 'dc-gi-wl-flash' );
					dataRow.offsetHeight; // eslint-disable-line no-unused-expressions
					dataRow.classList.add( 'dc-gi-wl-flash' );
				}

				// Update Last Submitted in the detail panel.
				var now    = new Date();
				var nowStr = now.getFullYear() + '-'
					+ String( now.getMonth() + 1 ).padStart( 2, '0' ) + '-'
					+ String( now.getDate() ).padStart( 2, '0' ) + ' '
					+ String( now.getHours() ).padStart( 2, '0' ) + ':'
					+ String( now.getMinutes() ).padStart( 2, '0' );
				if ( detailRow ) {
					var submittedEl = detailRow.querySelector( '[data-is-submitted]' );
					if ( submittedEl ) { submittedEl.textContent = fmtDate( nowStr ); }
				}
			} ).fail( function() {
				window.alert( i18n.isResubmitError );
				btn.disabled    = false;
				btn.textContent = i18n.isResubmit;
			} );
		} );
	}

	// ── Shared inspect-now AJAX + in-place update ────────────────────────────
	// Called by both the ↻ column button and the "Inspect" button inside the accordion.

	function doInspectNow( url, dataRow, detailRow, tbody, triggerBtn ) {
		var origText           = triggerBtn.textContent;
		triggerBtn.disabled    = true;
		triggerBtn.textContent = i18n.isInspecting;
		jQuery.post( ajaxUrl, { action: 'dc_gi_is_inspect_now', nonce: nonce, url: url },
		function( resp ) {
			triggerBtn.disabled    = false;
			triggerBtn.textContent = origText;
			if ( ! resp || ! resp.success ) {
				window.alert( resp && resp.data ? resp.data : i18n.isInspectError );
				return;
			}
			var freshRow = resp.data.row;
			if ( ! freshRow ) { return; }
			var pageOffset = ( isPage - 1 ) * 25;
			var idx        = parseInt( detailRow.getAttribute( 'data-idx' ), 10 );
			var rowIndex   = idx - pageOffset;
			var tmp        = document.createElement( 'tbody' );
			tmp.innerHTML  = buildDetailRow( freshRow, pageOffset, rowIndex, resp.data );
			var newDetail  = tmp.querySelector( '.dc-gi-is-detail-row' );
			if ( ! newDetail ) { return; }

			// Always open, replace in-place, sync icon, scroll into view.
			newDetail.style.display = '';
			detailRow.parentNode.replaceChild( newDetail, detailRow );
			var icon = dataRow ? dataRow.querySelector( '.dc-gi-is-expand-icon' ) : null;
			if ( icon ) { icon.textContent = '\u2303'; }
			newDetail.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );

			// Update data-row verdict / coverage / last-inspected cells.
			if ( dataRow ) {
				var cells    = dataRow.querySelectorAll( 'td' );
				var newBadge = verdictBadge[ freshRow.index_verdict ]
					|| '<span style="color:#7a8499">' + esc( freshRow.index_verdict ) + '</span>';
				if ( cells[ 2 ] ) { cells[ 2 ].innerHTML = newBadge; }
				if ( cells[ 3 ] ) {
					cells[ 3 ].textContent = freshRow.coverage_state || '\u2014';
					cells[ 3 ].title       = freshRow.coverage_state || '';
				}
				if ( cells[ 5 ] ) { cells[ 5 ].textContent = fmtDate( freshRow.last_inspected ); }
			}

			// Re-wire interactive buttons in the newly inserted detail row.
			var newResubmit = newDetail.querySelector( '.dc-gi-is-resubmit-btn' );
			if ( newResubmit ) { wireResubmitBtn( newResubmit, tbody ); }
			var newInspectBtn = newDetail.querySelector( '.dc-gi-is-inspect-detail-btn' );
			if ( newInspectBtn ) { wireInspectDetailBtn( newInspectBtn, tbody ); }
		} ).fail( function() {
			triggerBtn.disabled    = false;
			triggerBtn.textContent = origText;
			window.alert( i18n.isInspectError );
		} );
	}

	function wireInspectDetailBtn( btn, tbody ) {
		btn.addEventListener( 'click', function( e ) {
			e.preventDefault();
			e.stopPropagation();
			var url       = btn.getAttribute( 'data-url' ) || '';
			if ( ! url ) { return; }
			var detailRow = btn.closest( '.dc-gi-is-detail-row' );
			var idx       = detailRow ? detailRow.getAttribute( 'data-idx' ) : null;
			var dataRow   = idx ? tbody.querySelector( '.dc-gi-is-data-row[data-idx="' + idx + '"]' ) : null;
			doInspectNow( url, dataRow, detailRow, tbody, btn );
		} );
	}

	function renderUrlTable( rows, page, total ) {
		var tbody = document.getElementById( 'dc-gi-is-url-tbody' );
		if ( ! tbody ) { return; }
		var offset = ( page - 1 ) * 25;
		if ( ! rows || ! rows.length ) {
			tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#7a8499;padding:24px">' + esc( i18n.isNoUrlsMatch ) + '</td></tr>';
		} else {
			var html = '';
			for ( var i = 0; i < rows.length; i++ ) {
				var row    = rows[ i ];
				var badge  = verdictBadge[ row.index_verdict ] || '<span style="color:#7a8499">' + esc( row.index_verdict ) + '</span>';
				var url    = row.url || '';
				var urlDisp = url.replace( /^https?:\/\/[^/]+/, '' ) || url;
				html += '<tr class="dc-gi-is-data-row" data-idx="' + (offset+i) + '" style="cursor:pointer">';
				html += '<td style="color:#7a8499;font-size:12px">' + ( offset + i + 1 ) + '</td>';
				html += '<td style="overflow:hidden"><a href="' + esc( url ) + '" target="_blank" rel="noopener noreferrer" style="color:#6ab0f5;font-size:12px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc( url ) + '">' + esc( urlDisp ) + '</a></td>';
				html += '<td>' + badge + '</td>';
				html += '<td style="font-size:12px;color:#c8d0e0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc( row.coverage_state ) + '">' + esc( row.coverage_state || '\u2014' ) + '</td>';
				html += '<td style="font-size:12px;color:#7a8499">' + esc( fmtDate( row.last_crawl_time ) ) + '</td>';
				html += '<td style="font-size:12px;color:#7a8499">' + esc( fmtDate( row.last_inspected ) ) + '</td>';
				html += '<td style="font-size:12px;color:#7a8499;text-align:center"><span class="dc-gi-is-expand-icon" style="cursor:pointer;font-size:14px" title="' + esc( i18n.isShowDetails ) + '">\u2304</span></td>';
				html += '<td style="font-size:12px;text-align:center">'
					+ '<button type="button" class="button button-small dc-gi-is-inspect-btn"'
					+ ' data-url="' + esc( url ) + '" title="' + esc( i18n.isInspectNow ) + '"'
					+ ' style="padding:2px 5px;font-size:12px;line-height:1.4;min-height:0">\u21BB</button>'
					+ '</td>';
				html += '</tr>';
				html += buildDetailRow( row, offset, i );
			}
			tbody.innerHTML = html;

			// Wire expand/collapse.
			tbody.querySelectorAll( '.dc-gi-is-data-row' ).forEach( function(tr) {
				tr.addEventListener( 'click', function(e) {
					if ( e.target.tagName === 'A' || e.target.tagName === 'BUTTON' ) { return; }
					var idx     = tr.getAttribute( 'data-idx' );
					var detail  = tbody.querySelector( '.dc-gi-is-detail-row[data-idx="' + idx + '"]' );
					if ( ! detail ) { return; }
					var open = 'none' === detail.style.display;
					detail.style.display = open ? '' : 'none';
					var icon = tr.querySelector( '.dc-gi-is-expand-icon' );
					if ( icon ) { icon.textContent = open ? '\u2303' : '\u2304'; }
				} );
			} );

			tbody.querySelectorAll( '.dc-gi-is-resubmit-btn' ).forEach( function( btn ) {
				wireResubmitBtn( btn, tbody );
			} );

			// Wire Inspect Now column buttons (↻).
			tbody.querySelectorAll( '.dc-gi-is-inspect-btn' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function( e ) {
					e.preventDefault();
					e.stopPropagation();
					var url       = btn.getAttribute( 'data-url' ) || '';
					if ( ! url ) { return; }
					var dataRow   = btn.closest( '.dc-gi-is-data-row' );
					var idx       = dataRow ? dataRow.getAttribute( 'data-idx' ) : null;
					if ( ! idx ) { return; }
					var detailRow = tbody.querySelector( '.dc-gi-is-detail-row[data-idx="' + idx + '"]' );
					if ( ! detailRow ) { return; }
					doInspectNow( url, dataRow, detailRow, tbody, btn );
				} );
			} );

			// Wire "Inspect" buttons inside the accordion detail rows.
			tbody.querySelectorAll( '.dc-gi-is-inspect-detail-btn' ).forEach( function( btn ) {
				wireInspectDetailBtn( btn, tbody );
			} );

			// Reset expand-all state on each table render.
			isAllExpanded = false;
			var expandBtn = document.getElementById( 'dc-gi-is-expand-all' );
			if ( expandBtn ) { expandBtn.textContent = i18n.isExpandAll; }
		}
		// Pagination info.
		var info = document.getElementById( 'dc-gi-is-page-info' );
		if ( info ) { info.textContent = i18n.isPage + ' ' + page + ' ' + i18n.isOf + ' ' + isTotalPages + ' (' + total + ' ' + i18n.isUrls + ')'; }
		document.getElementById( 'dc-gi-is-prev' ).disabled = page <= 1;
		document.getElementById( 'dc-gi-is-next' ).disabled = page >= isTotalPages;
		var ts = document.getElementById( 'dc-gi-is-tbl-ts' );
		if ( ts ) { ts.textContent = i18n.isUpdated + ' ' + new Date().toLocaleTimeString(); }
	}

	// ── Wire up events ───────────────────────────────────────────────────────

	document.getElementById( 'dc-gi-is-prev' ).addEventListener( 'click', function () {
		if ( isPage > 1 ) { loadUrlTable( isPage - 1, isFilter, null, null ); }
	} );
	document.getElementById( 'dc-gi-is-next' ).addEventListener( 'click', function () {
		if ( isPage < isTotalPages ) { loadUrlTable( isPage + 1, isFilter, null, null ); }
	} );

	document.querySelectorAll( '.dc-gi-is-filter-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			document.querySelectorAll( '.dc-gi-is-filter-btn' ).forEach( function ( b ) { b.classList.remove( 'dc-gi-is-filter-active' ); } );
			btn.classList.add( 'dc-gi-is-filter-active' );
			isCoverageFilter = '';
			loadUrlTable( 1, btn.dataset.filter || '', null, null );
		} );
	} );

	// Column header sort clicks.
	document.querySelectorAll( '#dc-gi-is-url-tbl thead th[data-col]' ).forEach( function(th) {
		th.addEventListener( 'click', function() {
			var col = th.getAttribute( 'data-col' );
			var newOrder = ( col === isOrderBy && 'DESC' === isOrder ) ? 'ASC' : 'DESC';
			loadUrlTable( 1, isFilter, col, newOrder );
		} );
	} );

	// ── Search Analytics fetch button ────────────────────────────────────────

	var $analyticsBtn = document.getElementById( 'dc-gi-analytics-fetch-btn' );
	if ( $analyticsBtn ) {
		$analyticsBtn.addEventListener( 'click', function () {
			var days   = parseInt( document.getElementById( 'dc-gi-analytics-days' ).value, 10 ) || dcGiPoll.analyticsDefaultDays;
			var status = document.getElementById( 'dc-gi-analytics-status' );
			$analyticsBtn.disabled = true;
			$analyticsBtn.textContent = i18n.isFetching;
			if ( status ) { status.textContent = i18n.isFetching; }
			jQuery.post( ajaxUrl, { action: 'dc_gi_fetch_analytics', nonce: nonce, days: days }, function ( r ) {
				$analyticsBtn.disabled = false;
				$analyticsBtn.textContent = i18n.isFetchAnalytics;
				if ( ! r || ! r.success ) {
					if ( status ) { status.textContent = i18n.isFetchAnalyticsError; }
					return;
				}
				var d = r.data;
				if ( status ) {
					if ( d.ok && d.last_updated ) {
						status.textContent = i18n.isLastFetched + ' ' + d.last_updated + ' UTC (' + d.updated + ' ' + i18n.isRowsUpdated + ')';
					} else if ( d.ok ) {
						status.textContent = i18n.isFetchComplete + ' ' + d.updated + ' ' + i18n.isRowsUpdated + '.';
					} else {
						status.textContent = i18n.isWarning + ' ' + ( d.message || i18n.isNoDataReturned );
					}
				}
				// Reload the URL table to show fresh analytics data.
				loadUrlTable( isPage, isFilter, null, null );
			} ).fail( function () {
				$analyticsBtn.disabled = false;
				$analyticsBtn.textContent = i18n.isFetchAnalytics;
				if ( status ) { status.textContent = i18n.isRequestFailed; }
			} );
		} );
	}

	// ── URL search (A1) ──────────────────────────────────────────────────────

	var $si = document.getElementById( 'dc-gi-is-search' );
	if ( $si ) {
		$si.addEventListener( 'input', function () {
			clearTimeout( isSearchTimer );
			isSearchTimer = setTimeout( function () {
				isSearch = $si.value.trim();
				loadUrlTable( 1, isFilter, null, null );
			}, 300 );
		} );
	}

	// ── Expand all / collapse all (A2) ───────────────────────────────────────

	var $ea = document.getElementById( 'dc-gi-is-expand-all' );
	if ( $ea ) {
		$ea.addEventListener( 'click', function () {
			isAllExpanded = ! isAllExpanded;
			var tbody = document.getElementById( 'dc-gi-is-url-tbody' );
			if ( tbody ) {
				tbody.querySelectorAll( '.dc-gi-is-detail-row' ).forEach( function ( dr ) {
					dr.style.display = isAllExpanded ? '' : 'none';
				} );
				tbody.querySelectorAll( '.dc-gi-is-expand-icon' ).forEach( function ( icon ) {
					icon.textContent = isAllExpanded ? '\u2303' : '\u2304';
				} );
			}
			$ea.textContent = isAllExpanded ? i18n.isCollapseAll : i18n.isExpandAll;
		} );
	}

	// ── Coverage bar click → filter (A3) ─────────────────────────────────────

	document.querySelectorAll( '.dc-gi-coverage-bar' ).forEach( function ( bar ) {
		bar.addEventListener( 'click', function () {
			isCoverageFilter = bar.getAttribute( 'data-coverage-filter' ) || '';
			isFilter = '';
			document.querySelectorAll( '.dc-gi-is-filter-btn' ).forEach( function ( b ) {
				b.classList.remove( 'dc-gi-is-filter-active' );
			} );
			var allBtn = document.querySelector( '.dc-gi-is-filter-btn[data-filter=""]' );
			if ( allBtn ) { allBtn.classList.add( 'dc-gi-is-filter-active' ); }
			loadUrlTable( 1, '', null, null );
		} );
	} );

	// ── Bulk re-submit (B3) ──────────────────────────────────────────────────

	var $bulk = document.getElementById( 'dc-gi-is-bulk-resubmit' );
	if ( $bulk ) {
		$bulk.addEventListener( 'click', function () {
			if ( ! window.confirm( i18n.isBulkConfirm ) ) { return; }
			$bulk.disabled = true;
			var $st = document.getElementById( 'dc-gi-is-bulk-status' );
			if ( $st ) { $st.textContent = i18n.isBulkWorking; }
			jQuery.post( ajaxUrl, { action: 'dc_gi_is_bulk_resubmit', nonce: nonce }, function ( r ) {
				$bulk.disabled = false;
				if ( $st ) { $st.textContent = r && r.success ? r.data.message : i18n.isBulkError; }
				if ( r && r.success ) {
					var qt = r.data.queue_total || 0;
					var hq = document.getElementById( 'dc-gi-header-queue' );
					if ( hq ) { hq.textContent = qt; }
					var bq = document.getElementById( 'dc-gi-queue-body-count' );
					if ( bq ) { bq.textContent = qt; }
				}
			} ).fail( function () {
				$bulk.disabled = false;
				if ( $st ) { $st.textContent = i18n.isRequestFailed; }
			} );
		} );
	}

	// Initial load — sort by Last Crawl descending.
	// B4: auto-stale analytics refresh — silently fetch if data is > 24 hours old.
	if ( dcGiPoll.analyticsAge > 24 && dcGiPoll.analyticsDefaultDays ) {
		jQuery.post( ajaxUrl, { action: 'dc_gi_fetch_analytics', nonce: nonce, days: dcGiPoll.analyticsDefaultDays },
		function ( r ) {
			if ( ! r || ! r.success ) { return; }
			var status = document.getElementById( 'dc-gi-analytics-status' );
			if ( status && r.data.ok && r.data.last_updated ) {
				status.textContent = i18n.isLastFetched + ' ' + r.data.last_updated + ' UTC ('
					+ r.data.updated + ' ' + i18n.isRowsUpdated + ')';
			}
			loadUrlTable( isPage, isFilter, null, null );
		} );
	}

	loadUrlTable( 1, '', 'last_crawl_time', 'DESC' );
}());
