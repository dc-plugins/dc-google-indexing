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
	var inspectBaseUrl = dcGiPoll.inspectBaseUrl;
	var i18n           = dcGiPoll.i18n;
	var timer          = null;
	var isPage         = 1;
	var isFilter       = '';
	var isTotalPages   = 1;
	var isOrderBy      = 'last_crawl_time';
	var isOrder        = 'DESC';

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

	function setNum( id, val ) {
		var el = document.querySelector( '#' + id + ' .dc-gi-stat-num' );
		if ( el ) { el.textContent = val; }
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

	// ── Load URL table page ──────────────────────────────────────────────────

	function loadUrlTable( page, filter, orderBy, order ) {
		isPage   = page;
		isFilter = filter;
		if ( orderBy ) { isOrderBy = orderBy; }
		if ( order   ) { isOrder   = order; }
		var tbody = document.getElementById( 'dc-gi-is-url-tbody' );
		if ( tbody ) { tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#7a8499;padding:24px">' + esc( i18n.isLoading ) + '</td></tr>'; }
		updateSortIcons();
		jQuery.post( ajaxUrl, {
			action:   'dc_gi_is_urls',
			nonce:    nonce,
			page:     page,
			filter:   filter,
			order_by: isOrderBy,
			order:    isOrder
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
		var html = '<div style="margin-top:8px">';
		items.forEach( function(rtype) {
			html += '<div style="margin-bottom:6px"><span style="font-size:11px;font-weight:600;color:#c8d0e0">' + esc(rtype.t || '') + '</span>';
			(rtype.i||[]).forEach( function(item) {
				if ( item.n ) { html += '<span style="font-size:11px;color:#7a8499;margin-left:6px">\u2014 ' + esc(item.n) + '</span>'; }
				(item.i||[]).forEach( function(iss) {
					var col = 'WARNING' === iss.s ? '#ff8d72' : '#fd5d93';
					html += '<div style="font-size:11px;margin-left:12px;color:' + col + '">\u26a0 ' + esc(iss.m) + ' <span style="opacity:.6">(' + esc(iss.s) + ')</span></div>';
				});
			});
			html += '</div>';
		});
		return html + '</div>';
	}

	function buildDetailRow( row, offset, i ) {
		var gc  = row.google_canonical || '';
		var uc  = row.user_canonical   || '';
		var ca  = row.crawled_as        || '';
		var rts = row.robots_txt_state  || '';
		var idx = row.indexing_state    || '';
		var rr  = buildRichResults( row.rich_results || '' );

		var html = '<tr class="dc-gi-is-detail-row" data-idx="' + (offset+i) + '" style="display:none">'
			+ '<td colspan="7" style="background:#111827;padding:0 0 0 48px;border-top:none">'
			+ '<div style="padding:12px 16px 12px 0;display:grid;grid-template-columns:1fr 1fr;gap:8px 24px;font-size:12px;max-width:900px">';

		if ( rts ) {
			html += '<div><span style="color:#7a8499">' + esc( i18n.isRobotsTxt ) + ':</span> '
				+ '<span style="color:' + ('ALLOWED' === rts ? '#00f2c3' : '#fd5d93') + '">' + esc(fmtState(rts)) + '</span></div>';
		}
		if ( idx ) {
			var idxOk = 'INDEXING_ALLOWED' === idx;
			html += '<div><span style="color:#7a8499">' + esc( i18n.isIndexing ) + ':</span> '
				+ '<span style="color:' + (idxOk ? '#00f2c3' : '#ff8d72') + '">' + esc(fmtState(idx)) + '</span></div>';
		}
		if ( ca ) {
			html += '<div><span style="color:#7a8499">' + esc( i18n.isCrawledAs ) + ':</span> <span style="color:#c8d0e0">' + esc(fmtState(ca)) + '</span></div>';
		}
		if ( row.page_fetch_state ) {
			html += '<div><span style="color:#7a8499">' + esc( i18n.isPageFetch ) + ':</span> <span style="color:#c8d0e0">' + esc(fmtState(row.page_fetch_state)) + '</span></div>';
		}
		if ( gc ) {
			var gcMatch = gc === row.url;
			html += '<div style="grid-column:1/-1"><span style="color:#7a8499">' + esc( i18n.isGoogleCanonical ) + ':</span> '
				+ '<a href="' + esc(gc) + '" target="_blank" rel="noopener noreferrer" style="color:' + (gcMatch ? '#00f2c3' : '#ff8d72') + ';font-size:11px">' + esc(gc) + '</a>'
				+ (gcMatch ? '' : ' <span style="color:#ff8d72;font-size:11px">(' + esc( i18n.isDiffersFromUserCanonical ) + ')</span>') + '</div>';
		}
		if ( uc && uc !== gc ) {
			html += '<div style="grid-column:1/-1"><span style="color:#7a8499">' + esc( i18n.isUserCanonical ) + ':</span> '
				+ '<a href="' + esc(uc) + '" target="_blank" rel="noopener noreferrer" style="color:#6ab0f5;font-size:11px">' + esc(uc) + '</a></div>';
		}
		if ( row.last_submitted && '0000-00-00 00:00:00' !== row.last_submitted ) {
			html += '<div><span style="color:#7a8499">' + esc( i18n.isLastSubmitted ) + ':</span> <span style="color:#c8d0e0">' + esc(fmtDate(row.last_submitted)) + '</span></div>';
		}

		// Search Analytics data.
		if ( row.sa_updated ) {
			var ctr    = row.sa_ctr      != null ? ( parseFloat( row.sa_ctr )      * 100 ).toFixed( 1 ) + '%' : '\u2014';
			var pos    = row.sa_position != null ? parseFloat( row.sa_position ).toFixed( 1 )                 : '\u2014';
			var clicks = row.sa_clicks   != null ? parseInt( row.sa_clicks, 10 )                              : '\u2014';
			var impr   = row.sa_impressions != null ? parseInt( row.sa_impressions, 10 )                      : '\u2014';
			html += '<div style="grid-column:1/-1;margin-top:4px;padding-top:8px;border-top:1px solid rgba(45,53,85,.6)">'
				+ '<span style="color:#7a8499;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px">' + esc( i18n.isSearchAnalytics ) + '</span>'
				+ '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:8px">'
				+ '<div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px;text-align:center"><div style="font-size:18px;font-weight:700;color:#1d8cf8">' + esc(String(clicks)) + '</div><div style="font-size:10px;color:#7a8499;margin-top:3px;text-transform:uppercase">' + esc( i18n.isClicks ) + '</div></div>'
				+ '<div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px;text-align:center"><div style="font-size:18px;font-weight:700;color:#c8d0e0">' + esc(String(impr)) + '</div><div style="font-size:10px;color:#7a8499;margin-top:3px;text-transform:uppercase">' + esc( i18n.isImpressions ) + '</div></div>'
				+ '<div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px;text-align:center"><div style="font-size:18px;font-weight:700;color:#00f2c3">' + esc(ctr) + '</div><div style="font-size:10px;color:#7a8499;margin-top:3px;text-transform:uppercase">' + esc( i18n.isCtr ) + '</div></div>'
				+ '<div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px;text-align:center"><div style="font-size:18px;font-weight:700;color:#ff8d72">' + esc(pos) + '</div><div style="font-size:10px;color:#7a8499;margin-top:3px;text-transform:uppercase">' + esc( i18n.isAvgPosition ) + '</div></div>'
				+ '</div>'
				+ '<div style="font-size:10px;color:#7a8499;margin-top:6px">' + esc( i18n.isUpdated ) + ' ' + esc( row.sa_updated ) + ' UTC</div>'
				+ '</div>';
		}

		if ( rr ) {
			html += '<div style="grid-column:1/-1"><span style="color:#7a8499;font-weight:600">' + esc( i18n.isRichResults ) + '</span>' + rr + '</div>';
		}
		html += '<div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">'
			+ '<a class="button button-small" href="' + inspectBaseUrl + '&inspect_url=' + encodeURIComponent( row.url ) + '">' + esc( i18n.isInspect ) + '</a>';
		if ( 'PASS' !== row.index_verdict ) {
			html += '<button type="button" class="button button-small dc-gi-is-resubmit-btn" data-url="' + esc( row.url ) + '">' + esc( i18n.isResubmit ) + '</button>';
		}
		html += '</div>';

		html += '</div></td></tr>';
		return html;
	}

	function renderUrlTable( rows, page, total ) {
		var tbody = document.getElementById( 'dc-gi-is-url-tbody' );
		if ( ! tbody ) { return; }
		var offset = ( page - 1 ) * 25;
		if ( ! rows || ! rows.length ) {
			tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#7a8499;padding:24px">' + esc( i18n.isNoUrlsMatch ) + '</td></tr>';
		} else {
			var html = '';
			for ( var i = 0; i < rows.length; i++ ) {
				var row    = rows[ i ];
				var badge  = verdictBadge[ row.index_verdict ] || '<span style="color:#7a8499">' + esc( row.index_verdict ) + '</span>';
				var url    = row.url || '';
				var urlDisp = url.replace( /^https?:\/\/[^\/]+/, '' ) || url;
				html += '<tr class="dc-gi-is-data-row" data-idx="' + (offset+i) + '" style="cursor:pointer">';
				html += '<td style="color:#7a8499;font-size:12px">' + ( offset + i + 1 ) + '</td>';
				html += '<td style="overflow:hidden"><a href="' + esc( url ) + '" target="_blank" rel="noopener noreferrer" style="color:#6ab0f5;font-size:12px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc( url ) + '">' + esc( urlDisp ) + '</a></td>';
				html += '<td>' + badge + '</td>';
				html += '<td style="font-size:12px;color:#c8d0e0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc( row.coverage_state ) + '">' + esc( row.coverage_state || '\u2014' ) + '</td>';
				html += '<td style="font-size:12px;color:#7a8499">' + esc( fmtDate( row.last_crawl_time ) ) + '</td>';
				html += '<td style="font-size:12px;color:#7a8499">' + esc( fmtDate( row.last_inspected ) ) + '</td>';
				html += '<td style="font-size:12px;color:#7a8499;text-align:center"><span style="cursor:pointer;font-size:14px" title="' + esc( i18n.isShowDetails ) + '">\u2304</span></td>';
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
					var icon = tr.querySelector( 'td:last-child span' );
					if ( icon ) { icon.textContent = open ? '\u2303' : '\u2304'; }
				} );
			} );

			tbody.querySelectorAll( '.dc-gi-is-resubmit-btn' ).forEach( function(btn) {
				btn.addEventListener( 'click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					var url = btn.getAttribute( 'data-url' ) || '';
					if ( ! url ) { return; }
					btn.disabled = true;
					btn.textContent = i18n.isQueueing;
					jQuery.post( ajaxUrl, {
						action: 'dc_gi_watch_resubmit_one',
						nonce: nonce,
						url: url
					}, function(resp) {
						if ( ! resp || ! resp.success ) {
							window.alert( i18n.isResubmitError );
							btn.disabled = false;
							btn.textContent = i18n.isResubmit;
							return;
						}
						btn.textContent = i18n.isQueued;
						var queueCount = resp.data && resp.data.queue_count ? resp.data.queue_count : null;
						if ( null !== queueCount ) {
							var headerQueue = document.getElementById( 'dc-gi-header-queue' );
							if ( headerQueue ) { headerQueue.textContent = queueCount; }
							var bodyQueue = document.getElementById( 'dc-gi-queue-body-count' );
							if ( bodyQueue ) { bodyQueue.textContent = queueCount; }
						}
					} ).fail( function() {
						window.alert( i18n.isResubmitError );
						btn.disabled = false;
						btn.textContent = i18n.isResubmit;
					} );
				} );
			} );
		}
		// Pagination info.
		var info = document.getElementById( 'dc-gi-is-page-info' );
		if ( info ) { info.textContent = i18n.isPage + ' ' + page + ' ' + i18n.isOf + ' ' + isTotalPages + ' (' + total + ' ' + i18n.isUrls + ')'; }
		document.getElementById( 'dc-gi-is-prev' ).disabled = page <= 1;
		document.getElementById( 'dc-gi-is-next' ).disabled = page >= isTotalPages;
		var ts = document.getElementById( 'dc-gi-is-tbl-ts' );
		if ( ts ) { ts.textContent = i18n.isUpdated + ' ' + new Date().toLocaleTimeString(); }
	}

	// ── Refresh summary stat cards ───────────────────────────────────────────

	function doRefresh() {
		jQuery.post( ajaxUrl, { action: 'dc_gi_index_status', nonce: nonce }, function ( resp ) {
			if ( ! resp || ! resp.success ) { return; }
			var d = resp.data, v = d.verdicts || {};
			var inspErr = d.inspect_errors || 0;
			setNum( 'is-stat-total',    d.total );
			setNum( 'is-stat-pass',     v['PASS'] || 0 );
			setNum( 'is-stat-excluded', d.excluded || 0 );
			setNum( 'is-stat-fail',     v['FAIL'] || 0 );
			setNum( 'is-stat-errors',   inspErr );
			setNum( 'is-stat-age',      null != d.age_days ? d.age_days : '\u2014' );
			var ts = document.getElementById( 'dc-gi-is-ts' );
			if ( ts ) { ts.textContent = i18n.isStatsUpdated + ' ' + new Date().toLocaleTimeString(); }
			// Show/hide the inspection quota backoff notice.
			var qbEl = document.getElementById( 'dc-gi-is-quota-backoff' );
			if ( qbEl ) { qbEl.style.display = d.quota_backoff ? '' : 'none'; }
			// Also reload the URL table to stay current.
			loadUrlTable( isPage, isFilter, null, null );
		} );
	}

	function scheduleAuto() {
		clearInterval( timer );
		if ( document.getElementById( 'dc-gi-is-auto' ).checked ) {
			timer = setInterval( doRefresh, 30000 );
		}
	}

	// ── Wire up events ───────────────────────────────────────────────────────

	document.getElementById( 'dc-gi-is-auto' ).addEventListener( 'change', scheduleAuto );
	scheduleAuto();

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

	// Initial load — sort by Last Crawl descending.
	loadUrlTable( 1, '', 'last_crawl_time', 'DESC' );
}());
