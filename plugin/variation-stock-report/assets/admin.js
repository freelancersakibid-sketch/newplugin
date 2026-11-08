(function () {
	'use strict';

	function requestId(button, payload) {
		var signature = JSON.stringify(payload);
		if (button.dataset.commandSignature !== signature) {
			button.dataset.commandSignature = signature;
			var storageKey = 'vsrPending:' + vsrAdmin.userId + ':' + signature;
			button.dataset.storageKey = storageKey;
			var saved = null;
			try { saved = window.sessionStorage.getItem(storageKey); } catch (error) { /* Browser storage is optional. */ }
			if (!saved) {
				var bytes = new Uint8Array(16);
				window.crypto.getRandomValues(bytes);
				saved = Array.from(bytes, function (n) { return n.toString(16).padStart(2, '0'); }).join('');
				try { window.sessionStorage.setItem(storageKey, saved); } catch (error) { /* Keep the in-memory key. */ }
			}
			button.dataset.requestId = saved;
		}
		return button.dataset.requestId;
	}
	function completeRequest(button) {
		try { window.sessionStorage.removeItem(button.dataset.storageKey); } catch (error) { /* Browser storage is optional. */ }
		delete button.dataset.commandSignature;
	}

	/**
	 * POST one FormData request to WordPress AJAX and normalize JSON failures.
	 *
	 * @param {Object} data Request fields.
	 * @return {Promise<Object>} Parsed WordPress JSON response.
	 */
	function post(data) {
		var body = new FormData();

		Object.keys(data).forEach(function (key) {
			body.append(key, data[key]);
		});

		return fetch(vsrAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.text().then(function (text) {
				var json;

				try {
					json = JSON.parse(text);
				} catch (error) {
					throw new Error(vsrAdmin.messages.networkError);
				}

				json.httpStatus = response.status;
				return json;
			});
		});
	}

	/**
	 * Safely read importer state from localStorage, expiring stale sessions > 1 hour old.
	 *
	 * @return {Object|null} Saved state or null.
	 */
	function getImportState() {
		try {
			var raw = window.localStorage.getItem('vsrImportCursorV220:' + vsrAdmin.userId);
			if (!raw) {
				window.localStorage.removeItem('vsrImportOffset');
				return null;
			}
			var state = JSON.parse(raw);
			if (!state || typeof state.offset !== 'number' || state.offset <= 0) {
				return null;
			}
			if (Date.now() - (state.timestamp || 0) > 3600 * 1000) {
				window.localStorage.removeItem('vsrImportCursorV220:' + vsrAdmin.userId);
				return null;
			}
			return state;
		} catch (error) {
			return null;
		}
	}

	/**
	 * Safely persist importer progress state.
	 *
	 * @param {Object|null} state State object, or null to clear.
	 */
	function setImportState(state) {
		try {
			window.localStorage.removeItem('vsrImportOffset');
			if (null === state) {
				window.localStorage.removeItem('vsrImportCursorV220:' + vsrAdmin.userId);
			} else {
				state.timestamp = Date.now();
				window.localStorage.setItem('vsrImportCursorV220:' + vsrAdmin.userId, JSON.stringify(state));
			}
		} catch (error) {
			// localStorage can be unavailable in privacy-restricted browser contexts.
		}
	}

	/**
	 * Replace WordPress-style numbered integer placeholders in a localized string.
	 *
	 * @param {string} template Message template.
	 * @param {Array<number>} values Values.
	 * @return {string} Formatted message.
	 */
	function formatProgress(template, values) {
		return values.reduce(function (message, value, index) {
			return message.replace('%' + (index + 1) + '$d', String(value));
		}, template);
	}

	// Category multiselect dropdown
	var category = document.getElementById('vsr-category-select');
	if (category) {
		var toggle = category.querySelector('.vsr-multiselect-toggle');
		var panel = category.querySelector('.vsr-multiselect-panel');
		var doneButton = category.querySelector('[data-vsr-done]');
		var clearButton = category.querySelector('[data-vsr-clear]');

		function setOpen(open) {
			if (!panel || !toggle) {
				return;
			}

			panel.hidden = !open;
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		}

		function updateCategoryLabel() {
			if (!panel || !toggle) {
				return;
			}

			var checked = panel.querySelectorAll('input[type="checkbox"]:checked');
			if (0 === checked.length) {
				toggle.textContent = vsrAdmin.messages.allCategories;
			} else if (1 === checked.length) {
				toggle.textContent = checked[0].parentNode.textContent.trim();
			} else {
				toggle.textContent = vsrAdmin.messages.categoriesSelected.replace('%d', String(checked.length));
			}
		}

		if (toggle && panel) {
			toggle.addEventListener('click', function (event) {
				event.stopPropagation();
				setOpen(panel.hidden);
			});

			document.addEventListener('click', function (event) {
				if (!category.contains(event.target)) {
					setOpen(false);
				}
			});

			panel.addEventListener('change', updateCategoryLabel);
		}

		if (doneButton) {
			doneButton.addEventListener('click', function () {
				setOpen(false);
			});
		}

		if (clearButton) {
			clearButton.addEventListener('click', function () {
				category.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
					input.checked = false;
				});
				updateCategoryLabel();
			});
		}
	}

	// Select All checkbox for Bulk Stock Update
	var selectAll = document.getElementById('vsr-select-all');
	if (selectAll) {
		selectAll.addEventListener('change', function () {
			document.querySelectorAll('.vsr-row-check').forEach(function (checkbox) {
				checkbox.checked = selectAll.checked;
			});
		});
	}

	// Bulk Stock Update handler
	var bulkApplyBtn = document.getElementById('vsr-bulk-apply');
	var bulkOperation = document.getElementById('vsr-bulk-operation');
	var bulkQty = document.getElementById('vsr-bulk-qty');
	var bulkNote = document.getElementById('vsr-bulk-note');
	var bulkStatus = document.getElementById('vsr-bulk-status');

	if (bulkApplyBtn && bulkOperation && bulkQty && bulkStatus) {
		bulkApplyBtn.addEventListener('click', function () {
			var checkedBoxes = Array.prototype.slice.call(document.querySelectorAll('.vsr-row-check:checked'));
			if (0 === checkedBoxes.length) {
				bulkStatus.textContent = vsrAdmin.messages.bulkSelectItems;
				return;
			}

			var qtyVal = bulkQty.value.trim();
			if ('' === qtyVal || isNaN(Number(qtyVal)) || Number(qtyVal) < 0) {
				bulkStatus.textContent = vsrAdmin.messages.bulkInvalidQty;
				return;
			}

			var ids = checkedBoxes.map(function (cb) {
				return cb.value;
			}).join(',');

			if (!window.confirm(vsrAdmin.messages.confirmBulk)) { return; }
			var originalText = bulkApplyBtn.textContent;
			bulkApplyBtn.disabled = true;
			bulkApplyBtn.textContent = vsrAdmin.messages.bulkUpdating;
			bulkStatus.textContent = '';

			post({
				action: 'vsr_bulk_update_stock',
				nonce: vsrAdmin.bulkNonce,
				request_id: requestId(bulkApplyBtn, [ids, bulkOperation.value, qtyVal, bulkNote ? bulkNote.value.trim() : '']),
				item_ids: ids,
				operation: bulkOperation.value,
				quantity: qtyVal,
				note: bulkNote ? bulkNote.value.trim() : ''
			}).then(function (response) {
				if (!response.success) {
					var failures = response.data && response.data.results ? response.data.results.filter(function (r) { return r.status === 'failed'; }).map(function (r) { return '#' + r.item_id + ': ' + r.message; }).join(' ') : '';
					throw new Error((response.data && response.data.message ? response.data.message : vsrAdmin.messages.updateFailed) + ' ' + failures);
				}

				completeRequest(bulkApplyBtn);
				var count = response.data && typeof response.data.updated_count === 'number' ? response.data.updated_count : checkedBoxes.length;
				bulkStatus.textContent = vsrAdmin.messages.bulkSuccess.replace('%d', String(count));
				setTimeout(function () {
					window.location.reload();
				}, 650);
			}).catch(function (error) {
				bulkStatus.textContent = error.message || vsrAdmin.messages.networkError;
				bulkApplyBtn.disabled = false;
				bulkApplyBtn.textContent = originalText;
			});
		});
	}

	// Single-row Quick Stock Update handler
	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-vsr-update]');
		if (!button) {
			return;
		}

		var row = button.closest('[data-vsr-row]');
		if (!row) {
			return;
		}

		var input = row.querySelector('.vsr-qty-input');
		var noteInput = row.querySelector('.vsr-note-input');
		var message = row.querySelector('.vsr-row-message');
		var stockValue = row.querySelector('[data-vsr-stock-value]');
		if (!input || !message || !stockValue) {
			return;
		}

		var original = button.textContent;
		button.disabled = true;
		input.disabled = true;
		if (noteInput) {
			noteInput.disabled = true;
		}
		button.textContent = vsrAdmin.messages.updating;
		message.textContent = '';

		post({
			action: 'vsr_update_stock',
			nonce: vsrAdmin.updateNonce,
			request_id: requestId(button, [button.dataset.itemId, button.dataset.expectedStock, input.value, noteInput ? noteInput.value.trim() : '']),
			item_id: button.dataset.itemId,
			expected_stock: button.dataset.expectedStock,
			new_stock: input.value,
			note: noteInput ? noteInput.value.trim() : ''
		}).then(function (response) {
			if (!response.success) {
				throw new Error(response.data && response.data.message ? response.data.message : vsrAdmin.messages.updateFailed);
			}

			completeRequest(button);
			stockValue.textContent = response.data.new_stock_formatted;
			input.value = response.data.new_stock;
			button.dataset.expectedStock = response.data.new_stock;
			message.textContent = response.data.warning ? response.data.warning : vsrAdmin.messages.savedReloading;

			setTimeout(function () {
				window.location.reload();
			}, response.data.warning ? 1800 : 550);
		}).catch(function (error) {
			message.textContent = error.message || vsrAdmin.messages.networkError;
			button.disabled = false;
			input.disabled = false;
			if (noteInput) {
				noteInput.disabled = false;
			}
			button.textContent = original;
		});
	});

	// Stock Movement Audit History Modal
	var auditModal = document.getElementById('vsr-audit-modal');
	var auditTitle = document.getElementById('vsr-audit-title');
	var auditSubtitle = document.getElementById('vsr-audit-subtitle');
	var auditTbody = document.getElementById('vsr-audit-tbody');
	var auditStatus = document.getElementById('vsr-audit-status');
	var auditTypeFilter = document.getElementById('vsr-audit-type-filter');
	var auditLoadMore = document.getElementById('vsr-audit-load-more');
	var auditClose = document.getElementById('vsr-audit-close');
	var auditExportLink = document.getElementById('vsr-audit-export-link');

	var auditInvoker = null;
	function closeAudit() {
		auditGeneration++;
		auditBusy = false;
		auditModal.hidden = true;
		if (auditInvoker) { auditInvoker.focus(); }
	}
	document.addEventListener('keydown', function (event) {
		if (!auditModal || auditModal.hidden) { return; }
		if (event.key === 'Escape') { closeAudit(); }
		if (event.key === 'Tab') {
			var controls = Array.from(auditModal.querySelectorAll('button:not([hidden]), select, a[href]')).filter(function (el) { return !el.disabled && el.offsetParent !== null; });
			var first = controls[0], last = controls[controls.length - 1];
			if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
			if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
		}
	});
	var auditGeneration = 0;
	var auditBusy = false;
	var currentAuditQuery = {
		itemId: 0,
		ownerId: 0,
		offset: 0,
		cursor: 0,
		snapshot: 0
	};

	function createAuditRowElement(item) {
		var tr = document.createElement('tr');

		var tdDate = document.createElement('td');
		tdDate.textContent = item.date;
		tr.appendChild(tdDate);

		var tdProduct = document.createElement('td');
		var prodStrong = document.createElement('strong');
		prodStrong.textContent = item.product_name;
		tdProduct.appendChild(prodStrong);
		var prodSmall = document.createElement('small');
		prodSmall.textContent = '#' + item.item_id + (item.owner_id !== item.item_id ? ' (Owner #' + item.owner_id + ')' : '');
		tdProduct.appendChild(prodSmall);
		tr.appendChild(tdProduct);

		var tdType = document.createElement('td');
		var badge = document.createElement('span');
		badge.className = 'vsr-badge ' + (item.is_positive ? 'success' : 'warning');
		badge.textContent = item.type_label;
		tdType.appendChild(badge);
		tr.appendChild(tdType);

		var tdQty = document.createElement('td');
		var qtyStrong = document.createElement('strong');
		qtyStrong.className = item.is_positive ? 'vsr-delta-pos' : 'vsr-delta-neg';
		qtyStrong.textContent = item.quantity;
		tdQty.appendChild(qtyStrong);
		tr.appendChild(tdQty);

		var tdBeforeAfter = document.createElement('td');
		tdBeforeAfter.textContent = item.stock_before + ' → ' + item.stock_after;
		tr.appendChild(tdBeforeAfter);

		var tdSource = document.createElement('td');
		tdSource.textContent = item.source;
		if (item.order_id && item.order_url) {
			tdSource.appendChild(document.createTextNode(' · '));
			var orderLink = document.createElement('a');
			orderLink.href = item.order_url;
			orderLink.target = '_blank';
			orderLink.rel = 'noopener noreferrer';
			orderLink.textContent = '#' + item.order_id;
			tdSource.appendChild(orderLink);
		}
		tr.appendChild(tdSource);

		var tdUser = document.createElement('td');
		tdUser.textContent = item.user;
		tr.appendChild(tdUser);

		var tdNote = document.createElement('td');
		tdNote.textContent = item.note;
		tr.appendChild(tdNote);

		return tr;
	}

	function loadAuditLogs(append) {
		if (!auditModal || !auditTbody || !auditStatus) {
			return;
		}

		if (append && auditBusy) { return; }
		var generation = ++auditGeneration;
		auditBusy = true;
		if (!append) {
			currentAuditQuery.cursor = 0;
			currentAuditQuery.snapshot = 0;
			currentAuditQuery.offset = 0;
			auditTbody.innerHTML = '';
		}

		auditStatus.textContent = vsrAdmin.messages.auditLoading;
		if (auditLoadMore) {
			auditLoadMore.hidden = true;
		}

		if (auditExportLink) {
			var filteredExport = new URL(auditExportLink.href);
			filteredExport.searchParams.set('change_type', auditTypeFilter ? auditTypeFilter.value : '');
			auditExportLink.href = filteredExport.toString();
		}
		post({
			action: 'vsr_get_audit_logs',
			nonce: vsrAdmin.auditNonce,
			item_id: currentAuditQuery.itemId,
			owner_id: currentAuditQuery.ownerId,
			change_type: auditTypeFilter ? auditTypeFilter.value : '',
			before_id: currentAuditQuery.cursor,
			snapshot_id: currentAuditQuery.snapshot
		}).then(function (response) {
			if (generation !== auditGeneration || auditModal.hidden) { return; }
			auditBusy = false;
			if (!response.success) {
				throw new Error(response.data && response.data.message ? response.data.message : vsrAdmin.messages.networkError);
			}

			var rows = response.data.rows || [];
			rows.forEach(function (item) {
				auditTbody.appendChild(createAuditRowElement(item));
			});

			currentAuditQuery.offset += rows.length;
			currentAuditQuery.cursor = Number(response.data.next_id) || 0;
			currentAuditQuery.snapshot = Number(response.data.snapshot_id) || 0;

			if (0 === currentAuditQuery.offset) {
				auditStatus.textContent = vsrAdmin.messages.auditEmpty;
			} else {
				auditStatus.textContent = '';
			}

			if (auditLoadMore) {
				auditLoadMore.hidden = !response.data.has_more;
			}
		}).catch(function (error) {
			if (generation !== auditGeneration || auditModal.hidden) { return; }
			auditBusy = false;
			auditStatus.textContent = error.message || vsrAdmin.messages.networkError;
		});
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('[data-vsr-open-audit]');
		if (!trigger || !auditModal) {
			return;
		}

		auditInvoker = trigger;
		currentAuditQuery.itemId = Number(trigger.dataset.itemId) || 0;
		currentAuditQuery.ownerId = Number(trigger.dataset.ownerId) || 0;

		if (auditTitle) {
			auditTitle.textContent = trigger.dataset.itemTitle || vsrAdmin.messages.auditGlobalTitle;
		}
		if (auditSubtitle) {
			auditSubtitle.textContent = currentAuditQuery.itemId > 0
				? 'Item #' + currentAuditQuery.itemId + (currentAuditQuery.ownerId !== currentAuditQuery.itemId ? ' · Shared Stock Owner #' + currentAuditQuery.ownerId : '')
				: '';
		}
		if (auditTypeFilter) {
			auditTypeFilter.value = '';
		}
		if (auditExportLink) {
			var exportUrl = new URL(vsrAdmin.exportAuditUrl, window.location.origin);
			if (currentAuditQuery.itemId > 0) {
				exportUrl.searchParams.set('item_id', String(currentAuditQuery.itemId));
			}
			if (currentAuditQuery.ownerId > 0) {
				exportUrl.searchParams.set('owner_id', String(currentAuditQuery.ownerId));
			}
			auditExportLink.href = exportUrl.toString();
		}

		auditModal.hidden = false;
		if (auditTypeFilter) { auditTypeFilter.focus(); }
		loadAuditLogs(false);
	});

	if (auditTypeFilter) {
		auditTypeFilter.addEventListener('change', function () {
			loadAuditLogs(false);
		});
	}

	if (auditLoadMore) {
		auditLoadMore.addEventListener('click', function () {
			loadAuditLogs(true);
		});
	}

	if (auditClose && auditModal) {
		auditClose.addEventListener('click', closeAudit);
		auditModal.addEventListener('click', function (event) {
			if (event.target === auditModal) { closeAudit(); }
		});
	}

	// Historical Completed-Order Importer
	var importButton = document.getElementById('vsr-import-btn');
	var importStatus = document.getElementById('vsr-import-status');
	var importProgress = document.getElementById('vsr-import-progress');

	if (importButton && importStatus && importProgress) {
		importButton.addEventListener('click', function () {
			var savedState = getImportState();
			var offset = 0;
			var totals = {
				orders: 0,
				lines: 0,
				skipped: 0
			};

			if (savedState && savedState.offset > 0) {
				var resumePrompt = vsrAdmin.messages.resumeImportPrompt.replace('%d', String(savedState.offset));
				if (window.confirm(resumePrompt)) {
					offset = savedState.offset;
					totals.orders = Number(savedState.orders) || 0;
					totals.lines = Number(savedState.lines) || 0;
					totals.skipped = Number(savedState.skipped) || 0;
				} else {
					setImportState(null);
				}
			} else if (!window.confirm(vsrAdmin.messages.confirmImport)) {
				return;
			}

			importButton.disabled = true;
			importProgress.hidden = false;

			function run() {
				post({
					action: 'vsr_import_batch',
					nonce: vsrAdmin.importNonce,
					offset: offset
				}).then(function (response) {
					if (!response.success) {
						throw new Error(response.data && response.data.message ? response.data.message : vsrAdmin.messages.importFailed);
					}

					totals.orders += Number(response.data.imported_orders) || 0;
					totals.lines += Number(response.data.imported_lines) || 0;
					totals.skipped += Number(response.data.skipped) || 0;
					offset = Number(response.data.next_offset) || 0;

					setImportState({
						offset: offset,
						orders: totals.orders,
						lines: totals.lines,
						skipped: totals.skipped
					});

					importStatus.textContent = formatProgress(vsrAdmin.messages.importProgress, [
						offset,
						totals.orders,
						totals.lines,
						totals.skipped
					]);

					if (response.data.done) {
						setImportState(null);
						importStatus.textContent = vsrAdmin.messages.importComplete;
						setTimeout(function () {
							window.location.reload();
						}, 700);
					} else {
						setTimeout(run, 80);
					}
				}).catch(function (error) {
					importStatus.textContent = error.message || vsrAdmin.messages.networkError;
					importButton.disabled = false;
					importProgress.hidden = true;
				});
			}

			run();
		});
	}
}());
