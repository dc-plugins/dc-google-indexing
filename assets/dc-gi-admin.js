/* DC Google Indexing — Admin UI Scripts
   Watchlist check, QA scan, and Getting Started accordion.
   Depends on: dcGiPoll (localized via wp_localize_script)
*/

/* global jQuery, dcGiPoll */

// ── Watchlist Check ───────────────────────────────────────────────────────────
(function($){
	$(function(){
		var wcStopped = true;
		var wcXhr     = null;

		// Format the current time as 'YYYY-MM-DD HH:MM'.
		function fmtNow() {
			var n = new Date();
			var p = function(v){ return String(v).padStart(2,'0'); };
			return n.getFullYear()+'-'+p(n.getMonth()+1)+'-'+p(n.getDate())+' '+p(n.getHours())+':'+p(n.getMinutes());
		}

		// ── Badge helpers ──────────────────────────────────────────────────────

		function setBadgeRunning() {
			var $b = $('#dc-gi-watch-badge');
			$b.attr('class', 'dc-gi-poll-badge running');
			$b.html('<span class="dc-gi-spinner"></span><span class="dc-gi-badge-text">' + dcGiPoll.i18n.watchRunning + '</span>');
			$('#dc-gi-watch-check-btn').prop('disabled', true);
			$('#dc-gi-watch-stop-btn2').prop('disabled', false);
			$('#dc-gi-watch-stop-btn').prop('disabled', false);
			$('#dc-gi-watch-progress').show();
		}

		function setBadgeStopped() {
			var $b = $('#dc-gi-watch-badge');
			$b.attr('class', 'dc-gi-poll-badge stopped');
			$b.html('<span class="dc-gi-badge-text">' + dcGiPoll.i18n.watchStopped + '</span>');
			$('#dc-gi-watch-check-btn').prop('disabled', false);
			$('#dc-gi-watch-stop-btn2').prop('disabled', true);
			$('#dc-gi-watch-stop-btn').prop('disabled', true);
		}

		function setBadgeDone() {
			var $b = $('#dc-gi-watch-badge');
			$b.attr('class', 'dc-gi-poll-badge done');
			$b.html('<span class="dc-gi-badge-text">' + dcGiPoll.i18n.watchDone + '</span>');
			$('#dc-gi-watch-check-btn').prop('disabled', false);
			$('#dc-gi-watch-stop-btn2').prop('disabled', true);
			$('#dc-gi-watch-stop-btn').prop('disabled', true);
		}

		// ── FLIP animation ─────────────────────────────────────────────────────

		function flipToTop($row, newStatus, newCoverage) {
			var tbody = document.getElementById('dc-gi-wl-tbody');
			if (!tbody) return;

			var first = $row[0].getBoundingClientRect().top;
			tbody.insertBefore($row[0], tbody.firstChild);
			var last  = $row[0].getBoundingClientRect().top;
			var delta = first - last;

			$row[0].style.transition = 'none';
			$row[0].style.transform  = 'translateY(' + delta + 'px)';
			$row[0].offsetHeight; // eslint-disable-line no-unused-expressions
			$row[0].style.transition = 'transform 420ms cubic-bezier(0.34,1.56,0.64,1)';
			$row[0].style.transform  = 'translateY(0)';
			setTimeout(function(){ $row[0].style.transition = ''; $row[0].style.transform = ''; }, 450);

			var label = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
			$row.find('td').eq(1).html('<span class="dc-gi-wl-badge ' + newStatus + '">' + label + '</span>');
			$row.find('td').eq(2).text(newCoverage || '—');
			$row.find('td').eq(4).text(fmtNow());
			$row.removeClass('dc-gi-wl-flash');
			$row[0].offsetHeight; // eslint-disable-line no-unused-expressions
			$row.addClass('dc-gi-wl-flash');
		}

		// Remove auto-deleted rows from the table without reloading.
		function removeRow(url) {
			var $row = $('[data-wl-url="' + url.replace(/"/g,'&quot;') + '"]');
			if ($row.length) {
				$row.css('transition', 'opacity 0.4s').css('opacity', '0');
				setTimeout(function(){ $row.remove(); }, 420);
			}
		}

		// ── Core check loop ────────────────────────────────────────────────────

		function wcStart(startOffset) {
			wcStopped = false;
			setBadgeRunning();
			$('#dc-gi-wcp-label').text('Checking\u2026');
			$('#dc-gi-wcp-bar').css('width','0%').css('background','linear-gradient(90deg,#1d8cf8,#00f2c3)');
			wcCheckOne(startOffset || 0);
		}

		function wcStop() {
			wcStopped = true;
			if (wcXhr) { wcXhr.abort(); wcXhr = null; }
			setBadgeStopped();
			$('#dc-gi-wcp-label').text('Stopped.');
			$.post(dcGiPoll.ajaxurl, { action: 'dc_gi_watch_stop', nonce: dcGiPoll.nonce });
		}

		function wcCheckOne(offset) {
			if (wcStopped) return;
			wcXhr = $.post(dcGiPoll.ajaxurl, {
					action: 'dc_gi_watch_check_one',
					nonce:  dcGiPoll.nonce,
					offset: offset
				})
				.done(function(r) {
					if (wcStopped) return;
					if (typeof r === 'string') { try { r = JSON.parse(r); } catch(e) { r = null; } }
					if (!r || !r.success) {
						var errMsg = (r && r.data) ? String(r.data) : 'unexpected server response';
						$('#dc-gi-wcp-label').text('Error: ' + errMsg);
						setBadgeStopped();
						return;
					}
					var d     = r.data;
					var total = d.total || 1;
					var pct   = Math.round(d.checked / total * 100);
					$('#dc-gi-wcp-count').text(d.checked + ' / ' + total + ' (' + pct + '%)');
					$('#dc-gi-wcp-bar').css('width', pct + '%');
					if (d.url) $('#dc-gi-wcp-url').text(d.url + ' \u2192 ' + (d.coverage || d.status));
					if (typeof d.queue_count !== 'undefined') {
						$('#dc-gi-header-queue').text(d.queue_count);
						$('#dc-gi-queue-count').text(d.queue_count);
					}
					if (d.url) {
						if (d.auto_removed) {
							removeRow(d.url);
						} else {
							var $row = $('[data-wl-url="' + d.url.replace(/"/g,'&quot;') + '"]');
							if ($row.length) flipToTop($row, d.status, d.coverage);
						}
					}
					if (d.done) {
						$('#dc-gi-wcp-label').text('\u2705 Done \u2014 ' + d.checked + ' URLs checked.');
						$('#dc-gi-wcp-bar').css('background','#00f2c3');
						setBadgeDone();
						// Reload so the table reflects the latest DB state — entries that
						// were already indexed/removed are skipped by the server without
						// returning a per-URL result, leaving their rows visually stale.
						setTimeout(function() { window.location.reload(); }, 1000);
					} else {
						wcCheckOne(d.next);
					}
				})
				.fail(function(xhr) {
					if (xhr.statusText === 'abort') return;
					if (wcStopped) return;
					setTimeout(function(){ wcCheckOne(offset); }, 2000);
				});
		}

		// ── Button bindings ────────────────────────────────────────────────────

		$('#dc-gi-watch-check-btn').on('click', function(){ wcStart(0); });
		$('#dc-gi-watch-stop-btn, #dc-gi-watch-stop-btn2').on('click', wcStop);

		// "Clear All" — intercept the form submit and send via AJAX instead of
		// a full-page POST.  The form's own onsubmit="return confirm(...)" runs
		// first, so the native confirmation gate is preserved without duplicating it.
		$('form:has([name="action"][value="dc_gi_watch_clr"])').on('submit', function(e) {
			e.preventDefault();
			var $btn = $(this).find('button[type="submit"]');
			$btn.prop('disabled', true);
			$.post(dcGiPoll.ajaxurl, { action: 'dc_gi_watch_clr', nonce: dcGiPoll.nonce })
				.done(function(r) {
					if (r && r.success) {
						window.location.reload();
					} else {
						$btn.prop('disabled', false);
					}
				})
				.fail(function() {
					$btn.prop('disabled', false);
				});
		});

		// Per-row re-submit button.
		$(document).on('click', '.dc-gi-watch-resubmit-btn', function() {
			var $btn = $(this);
			var url  = $btn.data('url');
			if (!url) return;
			$btn.prop('disabled', true).text('\u2026');
			$.post(dcGiPoll.ajaxurl, {
				action: 'dc_gi_watch_resubmit_one',
				nonce:  dcGiPoll.nonce,
				url:    url
			})
			.done(function(r) {
				$btn.prop('disabled', false).text('\u21bb');
				if (r.success) {
					var $row = $('[data-wl-url]').filter(function() { return $(this).attr('data-wl-url') === url; });
					if ($row.length) {
						$row.find('td').eq(1).html('<span class="dc-gi-wl-badge pending">Pending</span>');
						$row.find('td').eq(2).text('\u2014');
						$row.find('td').eq(3).text(fmtNow());
						$row.removeClass('dc-gi-wl-flash');
						$row[0].offsetHeight; // eslint-disable-line no-unused-expressions
						$row.addClass('dc-gi-wl-flash');
					}
					if (typeof r.data.queue_count !== 'undefined') {
						$('#dc-gi-header-queue').text(r.data.queue_count);
						$('#dc-gi-queue-count').text(r.data.queue_count);
					}
				}
			})
			.fail(function() {
				$btn.prop('disabled', false).text('\u21bb');
			});
		});

		// ── Page-load: detect if cron is already running ───────────────────────

		if (dcGiPoll.watchActive) {
			// Cron started the check before this page loaded — show Running state
			// and resume the AJAX loop from the current offset so the UI stays live.
			setBadgeRunning();
			$('#dc-gi-wcp-label').text('Resuming check from background\u2026');
			wcStart(dcGiPoll.watchOffset || 0);
		} else {
			setBadgeStopped();
		}
	});
}(jQuery));

// ── QA Scan ───────────────────────────────────────────────────────────────────
(function($){
	$(function(){
		var qaStopped = true;
		var qaXhr     = null;

		// ── Issue label map ────────────────────────────────────────────────────

		var issueLabels = {
			fetch_error:           'Fetch Error',
			not_found:             '404 Not Found',
			http_error:            'HTTP Error',
			redirect:              'Redirect',
			noindex:               'Noindex',
			missing_title:         'Missing Title',
			missing_meta_desc:     'Missing Meta Desc',
			short_meta_desc:       'Short Meta Desc (\u226480)',
			missing_h1:            'Missing H1',
			non_canonical:         'Non-Canonical',
			duplicate_content:     'Duplicate Content',
			duplicate_short_desc:  'Duplicate Short Desc',
			thin_content:          'Thin Content (<150 words)',
			title_mismatch:        'Title Mismatch',
			duplicate_title:       'Duplicate Title'
		};

		var issueColors = {
			fetch_error:           '#fd5d93',
			not_found:             '#fd5d93',
			http_error:            '#fd5d93',
			redirect:              '#ff8d72',
			noindex:               '#ff8d72',
			missing_title:         '#ff8d72',
			missing_meta_desc:     '#ff8d72',
			short_meta_desc:       '#ff8d72',
			missing_h1:            '#7a8499',
			non_canonical:         '#ff8d72',
			duplicate_content:     '#ff8d72',
			duplicate_short_desc:  '#ff8d72',
			thin_content:          '#ff8d72',
			title_mismatch:        '#ff8d72',
			duplicate_title:       '#ff8d72'
		};

		function issueBadge(type) {
			var label = issueLabels[type] || type;
			var color = issueColors[type] || '#7a8499';
			return '<span style="display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;font-weight:600;background:rgba(255,255,255,.08);color:'+color+';margin:1px 2px">' + label + '</span>';
		}

		// ── Badge helpers ──────────────────────────────────────────────────────

		function qaSetBadgeRunning() {
			var $b = $('#dc-gi-qa-badge');
			$b.attr('class', 'dc-gi-poll-badge running');
			$b.html('<span class="dc-gi-spinner"></span><span>' + dcGiPoll.i18n.qaRunning + '</span>');
			$('#dc-gi-qa-start-btn').prop('disabled', true);
			$('#dc-gi-qa-stop-btn').prop('disabled', false);
			$('#dc-gi-qa-progress').show();
		}

		function qaSetBadgeStopped() {
			var $b = $('#dc-gi-qa-badge');
			$b.attr('class', 'dc-gi-poll-badge stopped');
			$b.html('<span>' + dcGiPoll.i18n.qaStopped + '</span>');
			$('#dc-gi-qa-start-btn').prop('disabled', false);
			$('#dc-gi-qa-stop-btn').prop('disabled', true);
		}

		function qaSetBadgeDone() {
			var $b = $('#dc-gi-qa-badge');
			$b.attr('class', 'dc-gi-poll-badge done');
			$b.html('<span>' + dcGiPoll.i18n.qaDone + '</span>');
			// Pending list was cleared by the scan — keep Start disabled until new URLs are flagged.
			$('#dc-gi-qa-start-btn').prop('disabled', true);
			$('#dc-gi-qa-stop-btn').prop('disabled', true);
		}

		// ── Counter updates ────────────────────────────────────────────────────

		var qaIssuesCount = parseInt($('#dc-gi-qa-stat-issues').text(), 10) || 0;
		var qaCleanCount  = parseInt($('#dc-gi-qa-stat-clean').text(), 10) || 0;
		var qaTotalCount  = parseInt($('#dc-gi-qa-stat-total').text(), 10) || 0;

		function qaAddRow(url, httpStatus, issues, title) {
			var $tbody = $('#dc-gi-qa-tbody');
			if (!$tbody.length) return;
			var issuesHtml = '';
			if (issues && issues.length) {
				for (var i = 0; i < issues.length; i++) {
					issuesHtml += issueBadge(issues[i]);
				}
			} else {
				issuesHtml = '<span style="color:#00f2c3;font-size:12px">\u2713 No issues</span>';
			}
			var statusColor = httpStatus === 200 ? '#00f2c3' : (httpStatus >= 400 ? '#fd5d93' : '#ff8d72');
			var row = '<tr data-qa-url="' + $('<span>').text(url).html() + '">' +
				'<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><a href="' + $('<span>').text(url).html() + '" target="_blank" rel="noopener noreferrer">' + $('<span>').text(url).html() + '</a></td>' +
				'<td style="color:' + statusColor + ';font-weight:600">' + (httpStatus || '—') + '</td>' +
				'<td>' + issuesHtml + '</td>' +
				'<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#7a8499">' + $('<span>').text(title || '—').html() + '</td>' +
				'</tr>';
			$tbody.prepend(row);

			// Update counters.
			qaTotalCount++;
			$('#dc-gi-qa-stat-total').text(qaTotalCount);
			if (issues && issues.length) {
				qaIssuesCount++;
				$('#dc-gi-qa-stat-issues').text(qaIssuesCount);
			} else {
				qaCleanCount++;
				$('#dc-gi-qa-stat-clean').text(qaCleanCount);
			}

			// Apply active filter.
			var filterVal = $('#dc-gi-qa-filter').val();
			if (filterVal && filterVal !== 'all') {
				var $lastRow = $tbody.find('tr:first');
				var hasIssue = $lastRow.find('[data-issue="' + filterVal + '"]').length > 0;
				var isClean  = filterVal === 'clean' && (!issues || !issues.length);
				if (!hasIssue && !isClean) $lastRow.hide();
			}
		}

		// ── Core scan loop ─────────────────────────────────────────────────────

		function qaStart(startOffset) {
			qaStopped = false;
			qaSetBadgeRunning();
			$('#dc-gi-qa-prog-label').text('Scanning\u2026');
			$('#dc-gi-qa-prog-bar').css('width','0%').css('background','linear-gradient(90deg,#1d8cf8,#00f2c3)');
			qaScanOne(startOffset || 0);
		}

		function qaStop() {
			qaStopped = true;
			if (qaXhr) { qaXhr.abort(); qaXhr = null; }
			qaSetBadgeStopped();
			$('#dc-gi-qa-prog-label').text('Stopped.');
			$.post(dcGiPoll.ajaxurl, { action: 'dc_gi_qa_stop', nonce: dcGiPoll.nonce });
		}

		function qaScanOne(offset) {
			if (qaStopped) return;
			qaXhr = $.post(dcGiPoll.ajaxurl, {
					action: 'dc_gi_qa_scan_one',
					nonce:  dcGiPoll.nonce,
					offset: offset
				})
				.done(function(r) {
					if (qaStopped) return;
					if (!r || !r.success) {
						var errMsg = (r && r.data) ? String(r.data) : 'unexpected server response';
						$('#dc-gi-qa-prog-label').text('Error: ' + errMsg);
						qaSetBadgeStopped();
						return;
					}
					var d     = r.data;
					var total = d.total || 1;
					var pct   = Math.round((d.offset + 1) / total * 100);
					$('#dc-gi-qa-prog-count').text((d.offset + 1) + ' / ' + total + ' (' + pct + '%)');
					$('#dc-gi-qa-prog-bar').css('width', pct + '%');
					if (d.url) {
						$('#dc-gi-qa-prog-url').text(d.url);
						qaAddRow(d.url, d.http_status, d.issues, d.title);
					}
					if (d.done) {
						$('#dc-gi-qa-prog-label').text('\u2705 Scan complete \u2014 ' + total + ' URLs checked.');
						$('#dc-gi-qa-prog-bar').css('background','#00f2c3');
						qaSetBadgeDone();
					} else {
						qaScanOne(d.next);
					}
				})
				.fail(function(xhr) {
					if (xhr.statusText === 'abort') return;
					if (qaStopped) return;
					setTimeout(function(){ qaScanOne(offset); }, 2000);
				});
		}

		// ── Filter ─────────────────────────────────────────────────────────────

		$('#dc-gi-qa-filter').on('change', function() {
			var val = $(this).val();
			$('#dc-gi-qa-tbody tr').each(function() {
				var $row   = $(this);
				var url    = $row.attr('data-qa-url') || '';
				if (!url) { $row.show(); return; }
				if (!val || val === 'all') {
					$row.show();
				} else if (val === 'clean') {
					var cleanText = $row.find('td').eq(2).text().trim();
					$row.toggle(cleanText === '\u2713 No issues');
				} else {
					var issueHtml = $row.find('td').eq(2).html() || '';
					$row.toggle(issueHtml.indexOf(issueLabels[val] || val) !== -1);
				}
			});
		});

		// ── Button bindings ────────────────────────────────────────────────────

		$('#dc-gi-qa-start-btn').on('click', function() {
			// Clear existing rows so the table fills fresh.
			$('#dc-gi-qa-tbody').empty();
			qaIssuesCount = 0; qaCleanCount = 0; qaTotalCount = 0;
			$('#dc-gi-qa-stat-issues').text('0');
			$('#dc-gi-qa-stat-clean').text('0');
			$('#dc-gi-qa-stat-total').text('0');
			qaStart(0);
		});
		$('#dc-gi-qa-stop-btn').on('click', qaStop);

		// ── Page-load: resume if scan was in progress ──────────────────────────

		if (dcGiPoll.qaActive) {
			qaSetBadgeRunning();
			$('#dc-gi-qa-prog-label').text('Resuming scan\u2026');
			qaStart(dcGiPoll.qaOffset || 0);
		} else {
			qaSetBadgeStopped();
		}
	});
}(jQuery));

// ── Getting Started accordion + JSON validator ────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
	// Accordion
	document.querySelectorAll('.dc-gi-step-header').forEach(function (header) {
		header.addEventListener('click', function () {
			var body   = header.nextElementSibling;
			var toggle = header.querySelector('.dc-gi-step-toggle');
			var hidden = body.hasAttribute('hidden');
			body.toggleAttribute('hidden', !hidden);
			toggle.textContent = hidden ? '\u25b2' : '\u25bc';
		});
	});

	// Live JSON validator
	var textarea = document.getElementById('dc-gi-json-input');
	var feedback = document.getElementById('dc-gi-json-feedback');
	if (textarea) {
		textarea.addEventListener('input', function () {
			var val = textarea.value.trim();
			if (!val) { feedback.innerHTML = ''; return; }
			try {
				var obj = JSON.parse(val);
				var errors = [];
				if (obj.type !== 'service_account') errors.push('\u274c "type" must be "service_account"');
				if (!obj.client_email) errors.push('\u274c Missing "client_email"');
				if (!obj.private_key) errors.push('\u274c Missing "private_key"');
				if (!obj.project_id) errors.push('\u274c Missing "project_id"');
				if (errors.length) {
					feedback.innerHTML = '<div class="dc-gi-callout err">' + errors.join('<br>') + '</div>';
				} else {
					feedback.innerHTML =
						'<div class="dc-gi-callout ok">' +
						'\u2705 <strong>Valid JSON key file detected!</strong><br>' +
						'<span style="color:#555">Account: <code>' + obj.client_email + '</code></span>' +
						'</div>';
				}
			} catch (e) {
				feedback.innerHTML = '<div class="dc-gi-callout err">\u274c Invalid JSON \u2014 check that you copied the entire file contents.</div>';
			}
		});
	}
});
