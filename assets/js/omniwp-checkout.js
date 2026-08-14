/* global jQuery, omniwpCheckoutConfig */
(function ($) {
	'use strict';

	var config = typeof omniwpCheckoutConfig !== 'undefined' ? omniwpCheckoutConfig : {};

	/* --- Utility: Debounce --- */
	function debounce(fn, delay) {
		var timer;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
		};
	}

	var CheckoutController = {
		init: function () {
			this.cacheDOM();
			this.bindEvents();
			this.initDraft();
			this.applyInitialSelectedAddress();
			this.initStickyBar();
			this.initCouponHandler();
			this.initLoadingOverlay();
		},

		/* =============================================================
		   Toast Notification System (replaces native alert)
		   ============================================================= */
		showToast: function (message, type) {
			type = type || 'error'; // 'success' | 'error' | 'warning' | 'info'
			var iconMap = {
				success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
				error:   '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
				warning: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
				info:    '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
			};

			var $container = $('#sl-toast-container');
			if (!$container.length) {
				$container = $('<div id="sl-toast-container" class="sl-toast-container"></div>').appendTo('body');
			}

			var $toast = $('<div class="sl-toast sl-toast--' + type + '">' +
				'<span class="sl-toast__icon">' + (iconMap[type] || iconMap.info) + '</span>' +
				'<span class="sl-toast__msg">' + $('<span/>').text(message).html() + '</span>' +
				'<button type="button" class="sl-toast__close" aria-label="Đóng">✕</button>' +
				'</div>');

			$container.append($toast);

			// Trigger animation
			setTimeout(function () { $toast.addClass('sl-toast--visible'); }, 30);

			// Auto-dismiss
			var autoDismiss = setTimeout(function () { dismissToast($toast); }, 5000);

			$toast.find('.sl-toast__close').on('click', function () {
				clearTimeout(autoDismiss);
				dismissToast($toast);
			});

			function dismissToast($el) {
				$el.removeClass('sl-toast--visible');
				setTimeout(function () { $el.remove(); }, 300);
			}
		},

		/* =============================================================
		   Custom Confirmation Dialog (replaces native confirm)
		   ============================================================= */
		showConfirmDialog: function (message, onConfirm, onCancel) {
			var $existing = $('#sl-confirm-dialog');
			if ($existing.length) $existing.remove();

			var html = '<div id="sl-confirm-dialog" class="sl-confirm-dialog">' +
				'<div class="sl-confirm-dialog__overlay"></div>' +
				'<div class="sl-confirm-dialog__box">' +
				'<div class="sl-confirm-dialog__icon">' +
				'<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
				'</div>' +
				'<p class="sl-confirm-dialog__msg">' + $('<span/>').text(message).html() + '</p>' +
				'<div class="sl-confirm-dialog__actions">' +
				'<button type="button" class="sl-btn sl-btn--outline sl-btn--sm" id="sl-confirm-cancel">Hủy</button>' +
				'<button type="button" class="sl-btn sl-btn--primary sl-btn--sm" id="sl-confirm-ok">Xác nhận</button>' +
				'</div></div></div>';

			var $dialog = $(html).appendTo('body');
			setTimeout(function () { $dialog.addClass('sl-confirm-dialog--visible'); }, 30);

			function closeDialog() {
				$dialog.removeClass('sl-confirm-dialog--visible');
				setTimeout(function () { $dialog.remove(); }, 250);
			}

			$dialog.on('click', '#sl-confirm-ok', function () {
				closeDialog();
				if (typeof onConfirm === 'function') onConfirm();
			});

			$dialog.on('click', '#sl-confirm-cancel, .sl-confirm-dialog__overlay', function () {
				closeDialog();
				if (typeof onCancel === 'function') onCancel();
			});
		},

		/* =============================================================
		   Loading Overlay (shown during AJAX / update_checkout)
		   ============================================================= */
		initLoadingOverlay: function () {
			var $overlay = $('<div id="sl-checkout-loading" class="sl-checkout-loading">' +
				'<div class="sl-checkout-loading__spinner"></div>' +
				'</div>');
			$('.sl-checkout-page-wrap').append($overlay);

			$('body').on('update_checkout', function () {
				$overlay.addClass('sl-checkout-loading--active');
			});

			$('body').on('updated_checkout checkout_error', function () {
				$overlay.removeClass('sl-checkout-loading--active');
			});
		},

		cacheDOM: function () {
			this.$cardsGrid        = $('#sl-address-cards-container');
			this.$pickerModal      = $('#sl-address-picker-modal');
			this.$modal            = $('#sl-address-modal');
			this.$modalForm        = $('#sl-address-modal-form');
			this.$openModalBtn     = $('#sl-btn-open-address-modal, #sl-card-add-first');
			this.$closeModalBtn    = $('#sl-address-modal-close, #sl-modal-cancel, #sl-address-modal-overlay');
			this.$openPickerBtn    = $('#sl-btn-open-address-picker');
			this.$closePickerBtn   = $('#sl-picker-modal-close, #sl-picker-modal-overlay');
			this.$pickerAddNewBtn  = $('#sl-picker-btn-add-new');
			this.$provinceSelect   = $('#sl-modal-state');
			this.$wardSelect       = $('#sl-modal-city');
			this.$wardCodeHidden   = $('#sl-modal-ward-code');
		},

		bindEvents: function () {
			var self = this;

			// Open Picker Modal ("Địa Chỉ Của Tôi") on Checkout
			$(document).on('click', '#sl-btn-open-address-picker', function (e) {
				e.preventDefault();
				var $summary = $('#sl-co-selected-address-summary');
				if ($summary.length) {
					var data = $summary.data('address');
					if (typeof data === 'string') {
						try { data = JSON.parse(data); } catch (err) { data = null; }
					}
					// If address is incomplete and user clicks "Cập Nhật Ngay", directly open Edit Modal!
					if (data && data.is_incomplete) {
						self.openEditModal(data);
						return;
					}
				}
				self.openPickerModal();
			});

			// Close Picker Modal
			$(document).on('click', '#sl-picker-modal-close, #sl-picker-modal-overlay', function (e) {
				e.preventDefault();
				self.closePickerModal();
			});

			// Click "+ Thêm Địa Chỉ Mới" inside Picker Modal
			$(document).on('click', '#sl-picker-btn-add-new', function (e) {
				e.preventDefault();
				self.closePickerModal();
				self.resetModalForm();
				$('#sl-modal-title').text('Thêm địa chỉ nhận hàng mới');
				self.openModal();
			});

			// Edit address button in Picker Modal or Account Hub
			$(document).on('click', '.sl-btn-edit-address', function (e) {
				e.preventDefault();
				e.stopPropagation();
				var data = $(this).data('address');
				if (typeof data === 'string') {
					try { data = JSON.parse(data); } catch (err) { data = null; }
				}
				if (data) {
					if (self.$pickerModal.is(':visible')) {
						self.closePickerModal();
					}
					self.openEditModal(data);
				}
			});

			// Set default address in Account Hub
			$(document).on('click', '.sl-btn-set-default', function (e) {
				e.preventDefault();
				var addressId = $(this).data('address-id');
				if (!addressId) return;

				var $btn = $(this);
				$btn.prop('disabled', true).text('Đang xử lý...');

				$.ajax({
					url: config.ajaxUrl || '/wp-admin/admin-ajax.php',
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'omniwp_set_default_address',
						nonce: config.nonce || '',
						address_id: addressId
					},
					success: function (res) {
						if (res.success) {
							window.location.reload();
						} else {
							self.showToast(res.data && res.data.message ? res.data.message : 'Có lỗi xảy ra.', 'error');
							$btn.prop('disabled', false).text('Đặt mặc định');
						}
					},
					error: function () {
						self.showToast('Không thể kết nối máy chủ. Vui lòng thử lại.', 'error');
						$btn.prop('disabled', false).text('Đặt mặc định');
					}
				});
			});

			// Delete address in Account Hub
			$(document).on('click', '.sl-btn-delete-address', function (e) {
				e.preventDefault();
				var addressId = $(this).data('address-id');
				if (!addressId) return;

				var confirmMsg = (config.i18n && config.i18n.confirmDelete) ? config.i18n.confirmDelete : 'Bạn có chắc muốn xóa địa chỉ này?';
				var $card = $(this).closest('.sl-address-card, .sl-picker-item');

				self.showConfirmDialog(confirmMsg, function () {
					$.ajax({
						url: config.ajaxUrl || '/wp-admin/admin-ajax.php',
						type: 'POST',
						dataType: 'json',
						data: {
							action: 'omniwp_delete_address',
							nonce: config.nonce || '',
							address_id: addressId
						},
						success: function (res) {
							if (res.success) {
								$card.fadeOut(200, function () { $(this).remove(); });
								self.showToast('Đã xóa địa chỉ thành công!', 'success');
							} else {
								self.showToast(res.data && res.data.message ? res.data.message : 'Có lỗi xảy ra khi xóa.', 'error');
							}
						},
						error: function () {
							self.showToast('Không thể kết nối máy chủ. Vui lòng thử lại.', 'error');
						}
					});
				});
			});

			// Toggle Product List accordion (targets product tbody rows so totals tfoot remains visible)
			$(document).on('click', '.sl-co-section__header--toggle', function (e) {
				e.preventDefault();
				var $section = $(this).closest('.sl-co-section--products');
				var $tbody = $section.find('.sl-co-product-table tbody');
				var $btnText = $('#sl-co-toggle-products .sl-toggle-text');

				if ($tbody.is(':visible')) {
					$section.addClass('is-collapsed');
					$tbody.slideUp(200, function () {
						$btnText.text('Xem sản phẩm ▼');
					});
				} else {
					$section.removeClass('is-collapsed');
					$tbody.slideDown(200, function () {
						$btnText.text('Thu gọn ▲');
					});
				}
			});

			// Select address item in Picker Modal
			$(document).on('click change', '.sl-picker-item', function (e) {
				if ($(e.target).hasClass('sl-btn-edit-address')) return;

				$('.sl-picker-item').removeClass('sl-picker-item--selected');
				$(this).addClass('sl-picker-item--selected');
				$(this).find('.sl-picker-radio').prop('checked', true);

				var data = $(this).data('address');
				if (typeof data === 'string') {
					try {
						data = JSON.parse(data);
					} catch (err) {
						data = null;
					}
				}
				if (data) {
					self.applyAddressToForm(data);
					self.updateSummaryLine(data);
					self.closePickerModal();
				}
			});

			// Add Form Modal Open & Close
			$(document).on('click', '#sl-btn-open-address-modal, #sl-card-add-first', function (e) {
				e.preventDefault();
				self.resetModalForm();
				$('#sl-modal-title').text('Thêm địa chỉ nhận hàng mới');
				self.openModal();
			});

			$(document).on('click', '#sl-address-modal-close, #sl-modal-cancel, #sl-address-modal-overlay', function (e) {
				e.preventDefault();
				self.closeModal();
			});

			// Close on ESC key
			$(document).on('keydown', function (e) {
				if (e.key === 'Escape') {
					if (self.$pickerModal.is(':visible')) {
						self.closePickerModal();
					}
					if (self.$modal.is(':visible')) {
						self.closeModal();
					}
				}
			});

			// Cascading wards when province changes in modal
			this.$provinceSelect.on('change', function () {
				var provCode = $(this).val();
				self.loadWards(provCode);
			});

			this.$wardSelect.on('change', function () {
				var selectedWardCode = $(this).find(':selected').data('code') || '';
				self.$wardCodeHidden.val(selectedWardCode);
			});

			// Toggle manual form fields
			$(document).on('click', '#sl-btn-toggle-billing-fields', function (e) {
				e.preventDefault();
				var $fields = $('.woocommerce-billing-fields__field-wrapper, .woocommerce-billing-fields .form-row');
				$fields.toggleClass('sl-user-expanded');
				if ($fields.hasClass('sl-user-expanded')) {
					$fields.slideDown(200);
					$(this).find('.sl-toggle-text').text('▲ Ẩn bớt form chi tiết');
				} else {
					$fields.slideUp(200);
					$(this).find('.sl-toggle-text').text('✏️ Chỉnh sửa chi tiết form người nhận');
				}
			});

			// Submit new/edited address
			this.$modalForm.on('submit', function (e) {
				e.preventDefault();
				self.submitNewAddress();
			});

			// Intercept Place Order click if address is incomplete!
			$(document).on('click', '#sl-co-sticky-submit, #place_order', function (e) {
				var $summary = $('#sl-co-selected-address-summary');
				if ($summary.length) {
					var data = $summary.data('address');
					if (typeof data === 'string') {
						try { data = JSON.parse(data); } catch (err) { data = null; }
					}
					if (data && data.is_incomplete) {
						e.preventDefault();
						e.stopPropagation();
						self.showToast('Địa chỉ nhận hàng chưa đầy đủ (thiếu Phường/Xã hoặc Tỉnh/Thành). Vui lòng bổ sung thông tin địa chỉ.', 'warning');
						self.openEditModal(data);
						return false;
					}
				}
			});

			// Autosave Draft (debounced 500ms)
			var debouncedSaveDraft = debounce(function () { self.saveDraft(); }, 500);
			$('form.checkout').on('input change', 'input, select, textarea', debouncedSaveDraft);
		},

		openPickerModal: function () {
			this.$pickerModal.addClass('is-open is-active').css('display', 'block').attr('aria-hidden', 'false');
		},

		closePickerModal: function () {
			this.$pickerModal.removeClass('is-open is-active').hide().attr('aria-hidden', 'true');
		},

		openModal: function () {
			this.$modal.addClass('is-open is-active').css('display', 'block').attr('aria-hidden', 'false');
		},

		closeModal: function () {
			this.$modal.removeClass('is-open is-active').hide().attr('aria-hidden', 'true');
			this.resetModalForm();
		},

		resetModalForm: function () {
			$('#sl-modal-address-id').val('');
			if (this.$modalForm.length && this.$modalForm[0].reset) {
				this.$modalForm[0].reset();
			}
			this.$wardSelect.html('<option value="">-- Chọn Phường / Xã --</option>').prop('disabled', true);
			this.$wardCodeHidden.val('');
		},

		openEditModal: function (data) {
			if (!data) return;
			var self = this;

			this.resetModalForm();
			$('#sl-modal-title').text('Cập nhật địa chỉ nhận hàng');
			$('#sl-modal-address-id').val(data.id || '');
			$('#sl-modal-name').val(data.first_name || '');
			$('#sl-modal-phone').val(data.phone || '');
			$('#sl-modal-address').val(data.address_1 || '');

			var provCode = data.state || data.city || '';
			if (provCode) {
				this.$provinceSelect.val(provCode);
				self.loadWards(provCode, function () {
					var wardCode = data.ward_code || data.ward || '';
					if (wardCode) {
						self.$wardSelect.find('option').filter(function () {
							return $(this).data('code') == wardCode || $(this).val() == data.ward_name;
						}).prop('selected', true);
						self.$wardCodeHidden.val(wardCode);
					}
				});
			}

			if (data.tag) {
				$('input[name="tag"][value="' + data.tag + '"]').prop('checked', true);
			}
			if (typeof data.is_default !== 'undefined') {
				$('input[name="is_default"]').prop('checked', !!data.is_default);
			}

			self.openModal();
		},

		applyInitialSelectedAddress: function () {
			var $summary = $('#sl-co-selected-address-summary');
			if ($summary.length) {
				var data = $summary.data('address');
				if (typeof data === 'string') {
					try {
						data = JSON.parse(data);
					} catch (err) {
						data = null;
					}
				}
				if (data) {
					this.applyAddressToForm(data);
				}
			}
		},

		updateSummaryLine: function (data) {
			if (!data) return;

			var firstName = (data.first_name || '').trim();
			var lastName  = (data.last_name || '').trim();
			var fullName  = (firstName + ' ' + lastName).trim() || firstName;
			var phone     = data.phone || '';
			var address1  = data.address_1 || '';
			var wardName  = data.ward_name || (data.ward && !/^\d+$/.test(data.ward) ? data.ward : '');
			var stateName = data.state_name || (data.state && !/^\d+$/.test(data.state) ? data.state : '');

			if (/^\d+$/.test(wardName)) {
				wardName = '';
			}
			if (/^\d+$/.test(stateName)) {
				stateName = '';
			}

			// If stateName is numeric or empty, try to resolve from province select option
			if (!stateName && (data.state || data.city)) {
				var provCode = data.state || data.city;
				var $opt = $('#sl-modal-state option[value="' + provCode + '"]');
				if ($opt.length && $opt.text() && !$opt.text().includes('--')) {
					stateName = $opt.text();
				}
			}

			// If wardName is numeric or empty, try to resolve from ward select option
			if (!wardName && (data.ward_code || data.ward)) {
				var wCode = data.ward_code || data.ward;
				var $wOpt = $('#sl-modal-city option[data-code="' + wCode + '"], #sl-modal-city option[value="' + wCode + '"]');
				if ($wOpt.length && $wOpt.text() && !/^\d+$/.test($wOpt.text()) && !$wOpt.text().includes('--')) {
					wardName = $wOpt.text();
				}
			}

			var locParts = [];
			if (address1) {
				locParts.push(address1);
			}
			if (wardName) {
				locParts.push(wardName);
			}
			if (stateName) {
				locParts.push(stateName);
			}

			var fullLoc = locParts.filter(function (v, i, a) {
				return a.indexOf(v) === i;
			}).join(', ');

			var isIncomplete = !wardName || !stateName || !address1.trim();
			data.is_incomplete = isIncomplete;
			data.ward_name     = wardName;
			data.state_name    = stateName;

			$('#sl-summary-name').text(fullName);
			if (phone) {
				$('#sl-summary-phone').text(phone);
				$('#sl-summary-phone-wrap').show();
			} else {
				$('#sl-summary-phone-wrap').hide();
			}
			$('#sl-summary-full-loc').text(fullLoc || address1);

			if (data.is_default) {
				$('#sl-summary-default-badge').show();
			} else {
				$('#sl-summary-default-badge').hide();
			}

			if (isIncomplete) {
				$('#sl-summary-warning-badge').css('display', 'inline-flex');
				$('#sl-btn-open-address-picker').html('Cập Nhật Ngay &gt;').addClass('sl-btn-change-address--incomplete');
				$('#sl-co-selected-address-summary').addClass('sl-co-address-single-line--incomplete');
			} else {
				$('#sl-summary-warning-badge').hide();
				$('#sl-btn-open-address-picker').html('Thay Đổi &gt;').removeClass('sl-btn-change-address--incomplete');
				$('#sl-co-selected-address-summary').removeClass('sl-co-address-single-line--incomplete');
			}

			$('#sl-co-selected-address-summary').data('address', data);
		},

		applyAddressToForm: function (addr) {
			if (!addr) return;

			var firstName = addr.first_name || '';
			var lastName  = addr.last_name || '';
			var phone     = addr.phone || '';
			var address1  = addr.address_1 || '';
			var stateCode = addr.state || addr.city || '';
			var wardCode  = addr.ward_code || addr.ward || '';

			// Synchronize BOTH Billing AND Shipping input fields in WooCommerce form!
			if (firstName) {
				$('#billing_first_name, #shipping_first_name').val(firstName);
			}
			if (lastName) {
				$('#billing_last_name, #shipping_last_name').val(lastName);
			}
			if (phone) {
				$('#billing_phone, #shipping_phone').val(phone);
			}
			if (address1) {
				$('#billing_address_1, #shipping_address_1').val(address1);
			}
			if (stateCode) {
				$('#billing_state, #shipping_state').val(stateCode).trigger('change');
			}

			// Collapse raw billing fields wrapper if summary exists
			var $fields = $('.woocommerce-billing-fields__field-wrapper, .woocommerce-billing-fields .form-row');
			if ($('#sl-co-selected-address-summary').length && !$fields.hasClass('sl-user-expanded')) {
				$fields.hide();
			}

			var populateCityWard = function () {
				var targetVal = wardCode;

				['#billing_city', '#shipping_city'].forEach(function (selector) {
					var $elem = $(selector);
					if (!$elem.length) return;

					if (targetVal && $elem.find('option[value="' + targetVal + '"]').length) {
						$elem.val(targetVal).trigger('change');
					} else if (addr.ward_name && $elem.find('option').length) {
						var $match = $elem.find('option').filter(function () {
							return $(this).text().trim() === (addr.ward_name || '').trim();
						});
						if ($match.length) {
							$elem.val($match.val()).trigger('change');
						} else if (targetVal) {
							$elem.val(targetVal).trigger('change');
						}
					} else if (targetVal) {
						$elem.val(targetVal).trigger('change');
					}
				});

				if (wardCode) {
					$('#_OmniWP_ward_code, #sl_ward_code, #shipping_ward_code').val(wardCode);
				}
				$('body').trigger('update_checkout');
			};

			populateCityWard();
			$(document).one('sl:wards-loaded', populateCityWard);
			setTimeout(populateCityWard, 350);
		},

		loadWards: function (provCode, callback) {
			var self = this;
			if (!provCode) {
				self.$wardSelect.html('<option value="">-- Chọn Phường / Xã --</option>').prop('disabled', true);
				if (typeof callback === 'function') callback();
				return;
			}

			self.$wardSelect.html('<option value="">Đang tải...</option>').prop('disabled', true);

			$.ajax({
				url: config.ajaxUrl || '/wp-admin/admin-ajax.php',
				type: 'GET',
				dataType: 'json',
				data: {
					action: 'omniwp_get_wards',
					province: provCode
				},
				success: function (res) {
					var wards = (res && res.data) ? res.data : res;
					var opts  = '<option value="">-- Chọn Phường / Xã --</option>';
					if (Array.isArray(wards)) {
						$.each(wards, function (i, w) {
							opts += '<option value="' + (w.name || w) + '" data-code="' + (w.code || '') + '">' + (w.name || w) + '</option>';
						});
					}
					self.$wardSelect.html(opts).prop('disabled', false);
					if (typeof callback === 'function') callback();
				},
				error: function () {
					self.$wardSelect.html('<option value="">-- Chọn Phường / Xã --</option>').prop('disabled', false);
					if (typeof callback === 'function') callback();
				}
			});
		},

		submitNewAddress: function () {
			var self     = this;
			var $btn     = $('#sl-modal-submit');
			var formData = this.$modalForm.serializeArray();
			var payload  = {
				action: 'omniwp_save_checkout_address',
				nonce: config.nonce || ''
			};

			$.each(formData, function (i, field) {
				payload[field.name] = field.value;
			});

			var selectedWardCode = self.$wardSelect.find(':selected').data('code') || self.$wardCodeHidden.val() || '';
			payload.ward_code    = selectedWardCode;

			var selectedWardText = self.$wardSelect.find(':selected').text() || '';
			if (selectedWardText && !selectedWardText.includes('--') && !selectedWardText.includes('Đang tải')) {
				payload.ward_name = selectedWardText;
			}
			var selectedStateText = self.$provinceSelect.find(':selected').text() || '';
			if (selectedStateText && !selectedStateText.includes('--')) {
				payload.state_name = selectedStateText;
			}

			$btn.prop('disabled', true).text('Đang lưu...');

			$.ajax({
				url: config.ajaxUrl || '/wp-admin/admin-ajax.php',
				type: 'POST',
				dataType: 'json',
				data: payload,
				success: function (res) {
					$btn.prop('disabled', false).text('Lưu và Chọn địa chỉ này');
					if (res.success) {
						var saved = res.data.saved_address;
						self.closeModal();

						// If saving from Account Hub tab, reload page to reflect new/updated address list!
						if ($('#sl-account-address-grid').length) {
							window.location.reload();
							return;
						}

						var stateDisplay = saved.state_name || saved.state || '';
						var wardDisplay  = saved.ward_name || saved.city || '';
						var fullAddress  = [saved.address_1, wardDisplay, stateDisplay].filter(Boolean).join(', ');

						saved.is_incomplete = !wardDisplay || !stateDisplay || !saved.address_1;

						// Prepend radio item to picker list
						var itemHtml = '<div class="sl-picker-item sl-picker-item--selected" data-address=\'' + JSON.stringify(saved) + '\'>' +
							'<input type="radio" name="sl_picker_address_radio" class="sl-picker-radio" checked />' +
							'<div class="sl-picker-item__info">' +
							'<div class="sl-picker-item__name-phone">' +
							'<strong class="sl-picker-name">' + (saved.first_name || '') + '</strong>' +
							(saved.phone ? '<span class="sl-picker-phone">(' + saved.phone + ')</span>' : '') +
							'</div>' +
							'<div class="sl-picker-item__address">' + fullAddress + '</div>' +
							'<div class="sl-picker-item__tags">' +
							(saved.is_default ? '<span class="sl-address-default-badge">Mặc Định</span>' : '') +
							(saved.is_incomplete ? '<span class="sl-address-warning-badge">⚠️ Thiếu Phường/Xã</span>' : '') +
							'</div>' +
							'</div>' +
							'<button type="button" class="sl-picker-item__edit-btn sl-btn-edit-address" data-address=\'' + JSON.stringify(saved) + '\'>Cập nhật</button>' +
							'</div>';

						$('.sl-picker-item').removeClass('sl-picker-item--selected').find('.sl-picker-radio').prop('checked', false);
						$('#sl-picker-address-list').prepend(itemHtml);

						self.applyAddressToForm(saved);
						self.updateSummaryLine(saved);
					} else {
						self.showToast(res.data && res.data.message ? res.data.message : 'Có lỗi xảy ra khi lưu địa chỉ.', 'error');
					}
				},
				error: function () {
					$btn.prop('disabled', false).text('Lưu và Chọn địa chỉ này');
					self.showToast('Không thể kết nối máy chủ. Vui lòng thử lại.', 'error');
				}
			});
		},

		saveDraft: function () {
			var draft = {
				first_name: $('#billing_first_name').val(),
				phone: $('#billing_phone').val(),
				email: $('#billing_email').val(),
				address_1: $('#billing_address_1').val(),
				comments: $('#order_comments').val()
			};
			try {
				localStorage.setItem('omniwp_checkout_draft', JSON.stringify(draft));
			} catch (e) {}
		},

		initDraft: function () {
			try {
				var saved = localStorage.getItem('omniwp_checkout_draft');
				if (saved) {
					var draft = JSON.parse(saved);
					if (!$('#billing_first_name').val() && draft.first_name) $('#billing_first_name').val(draft.first_name);
					if (!$('#billing_phone').val() && draft.phone) $('#billing_phone').val(draft.phone);
					if (!$('#billing_email').val() && draft.email) $('#billing_email').val(draft.email);
					if (!$('#billing_address_1').val() && draft.address_1) $('#billing_address_1').val(draft.address_1);
					if (!$('#order_comments').val() && draft.comments) $('#order_comments').val(draft.comments);
				}
			} catch (e) {}
		},

		initStickyBar: function () {
			var self = this;

			// Sticky bar submit — delegates to #place_order but ONLY if
			// incomplete-address intercept (bound earlier) did not block it.
			$(document).on('click', '#sl-co-sticky-submit', function (e) {
				// If the intercept handler already called stopPropagation, this won't fire.
				// Check if this is the initial click (not already delegated).
				if (e.isDefaultPrevented()) return;

				e.preventDefault();
				var $placeOrder = $('#place_order');
				if ($placeOrder.length) {
					$placeOrder.trigger('click');
				}
			});

			$('body').on('updated_checkout', function () {
				self.syncStickyTotal();
				self.syncAppliedCoupons();
				self.syncProductsCollapsedState();
			});

			self.syncStickyTotal();
			self.syncAppliedCoupons();
			self.syncProductsCollapsedState();
		},

		syncProductsCollapsedState: function () {
			var $section = $('.sl-co-section--products');
			if ($section.hasClass('is-collapsed')) {
				$section.find('.sl-co-product-table tbody').hide();
				$('#sl-co-toggle-products .sl-toggle-text').text('Xem sản phẩm ▼');
			}
		},

		syncStickyTotal: function () {
			var $stickyAmount = $('#sl-co-sticky-total');
			if (!$stickyAmount.length) return;

			var $totalCell = $('.order-total td .woocommerce-Price-amount, .order-total td .amount, .order-total td bdi');
			if ($totalCell.length) {
				$stickyAmount.html($totalCell.first().parent().html() || $totalCell.first().html());
				return;
			}

			var $totalTd = $('.order-total td');
			if ($totalTd.length) {
				$stickyAmount.html($totalTd.first().html());
			}
		},

		syncAppliedCoupons: function () {
			var $container = $('#sl-co-applied-coupons');
			var $chipsList = $('#sl-applied-chips-list');
			if (!$container.length || !$chipsList.length) return;

			var coupons = [];
			$('.cart-discount').each(function () {
				var classList = $(this).attr('class') || '';
				var match = classList.match(/coupon-([^\s]+)/);
				var label = $(this).find('th').text().replace(/Mã giảm giá:|Coupon:/gi, '').trim();
				if (match) {
					coupons.push({ code: match[1], label: label || match[1] });
				}
			});

			if (coupons.length > 0) {
				var ticketSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>';
				var html = '';
				coupons.forEach(function (c) {
					html += '<span class="sl-coupon-chip" data-code="' + c.code + '">' +
						ticketSvg + ' <strong>' + c.code.toUpperCase() + '</strong>' +
						'<button type="button" class="sl-btn-remove-coupon" data-code="' + c.code + '" title="Xóa mã">✕</button>' +
						'</span>';
				});
				$chipsList.html(html);
				$container.show();
				$('#sl-co-voucher-status-text').text('Đã chọn ' + coupons.length + ' mã');
				$('#sl-btn-open-voucher-picker').html('Thay Đổi &gt;');
			} else {
				$chipsList.empty();
				$container.hide();
				$('#sl-co-voucher-status-text').text('Chọn hoặc nhập mã');
				$('#sl-btn-open-voucher-picker').html('Chọn hoặc Nhập Mã &gt;');
			}
		},

		openVoucherPickerModal: function () {
			var $modal = $('#sl-voucher-picker-modal');
			if ($modal.length) {
				$modal.addClass('is-open is-active').css('display', 'block').attr('aria-hidden', 'false');
				$('body').css('overflow', 'hidden');
				this.updatePickerSummary();
			}
		},

		closeVoucherPickerModal: function () {
			var $modal = $('#sl-voucher-picker-modal');
			if ($modal.length) {
				$modal.removeClass('is-open is-active').hide().attr('aria-hidden', 'true');
				$('body').css('overflow', '');
			}
		},

		updatePickerSummary: function () {
			var selectedCount = 0;
			var totalSavings = 0;

			$('.sl-picker-vcard-radio:checked').each(function () {
				selectedCount++;
				var $card = $(this).closest('.sl-picker-vcard');
				var savings = parseFloat($card.data('savings')) || 0;
				totalSavings += savings;
			});

			var $summaryText = $('#sl-picker-summary-text');
			if (!$summaryText.length) return;

			if (selectedCount > 0) {
				var text = selectedCount + ' Voucher đã được chọn';
				if (totalSavings > 0) {
					text += ' - Tiết kiệm ' + totalSavings.toLocaleString('vi-VN') + 'đ';
				}
				$summaryText.html('<strong>' + text + '</strong>');
			} else {
				$summaryText.text('Chưa chọn Voucher nào');
			}
		},

		initCouponHandler: function () {
			var self = this;

			// Open Shopee Voucher Picker Modal
			$(document).on('click', '#sl-btn-open-voucher-picker, .sl-btn-open-voucher-picker', function (e) {
				e.preventDefault();
				self.openVoucherPickerModal();
			});

			// Close Shopee Voucher Picker Modal
			$(document).on('click', '#sl-voucher-picker-close, #sl-picker-btn-cancel', function (e) {
				e.preventDefault();
				self.closeVoucherPickerModal();
			});

			// Card click or Radio selection inside Voucher Picker Modal
			$(document).on('click', '.sl-picker-vcard--active', function (e) {
				if ($(e.target).hasClass('sl-picker-vcard-radio')) return;
				var $radio = $(this).find('.sl-picker-vcard-radio');
				if ($radio.length && !$radio.prop('disabled')) {
					var wasChecked = $radio.prop('checked');
					$radio.prop('checked', !wasChecked).trigger('change');
				}
			});

			$(document).on('change', '.sl-picker-vcard-radio', function () {
				$('.sl-picker-vcard').removeClass('sl-picker-vcard--selected');
				$('.sl-picker-vcard-radio:checked').each(function () {
					$(this).closest('.sl-picker-vcard').addClass('sl-picker-vcard--selected');
				});
				self.updatePickerSummary();
			});

			// Submit Voucher Selection in Modal ("ĐỒNG Ý")
			$(document).on('click', '#sl-picker-btn-apply', function (e) {
				e.preventDefault();
				var $btn = $(this);
				var selectedCodes = [];

				$('.sl-picker-vcard-radio:checked').each(function () {
					selectedCodes.push($(this).val());
				});

				$btn.prop('disabled', true).text('Đang áp dụng...');

				$.ajax({
					url: config.ajaxUrl || '/wp-admin/admin-ajax.php',
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'omniwp_apply_selected_vouchers',
						nonce: config.nonce || '',
						codes: selectedCodes.join(',')
					},
					success: function (res) {
						$btn.prop('disabled', false).text('ĐỒNG Ý');
						if (res && res.success) {
							self.closeVoucherPickerModal();
							$(document.body).trigger('update_checkout', { update_shipping_method: true });
						} else {
							var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Không thể áp dụng mã đã chọn.';
							alert(errMsg);
						}
					},
					error: function () {
						$btn.prop('disabled', false).text('ĐỒNG Ý');
						alert('Không thể kết nối máy chủ. Vui lòng thử lại.');
					}
				});
			});

			// Manual Input inside Modal ("ÁP DỤNG")
			$(document).on('click', '#sl-picker-manual-apply-btn, #sl-btn-apply-coupon', function (e) {
				e.preventDefault();
				var code = ($('#sl-picker-manual-code-input').val() || $('#sl-coupon-code-input').val() || '').trim();
				if (!code) return;

				var $btn = $(this);
				$btn.prop('disabled', true).text('Đang áp dụng...');

				$.ajax({
					type: 'POST',
					url: (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.wc_ajax_url ? wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon') : '/?wc-ajax=apply_coupon'),
					data: {
						security: (typeof wc_checkout_params !== 'undefined' ? wc_checkout_params.apply_coupon_nonce : ''),
						coupon_code: code
					},
					success: function () {
						$btn.prop('disabled', false).text('ÁP DỤNG');
						$('#sl-picker-manual-code-input, #sl-coupon-code-input').val('');
						self.closeVoucherPickerModal();
						$(document.body).trigger('update_checkout', { update_shipping_method: true });
					},
					error: function () {
						$btn.prop('disabled', false).text('ÁP DỤNG');
						self.closeVoucherPickerModal();
						$(document.body).trigger('update_checkout', { update_shipping_method: true });
					}
				});
			});

			// Remove Coupon Chip
			$(document).on('click', '.sl-btn-remove-coupon', function (e) {
				e.preventDefault();
				var code = $(this).data('code') || $(this).attr('data-code');
				if (!code) return;

				var $chip = $(this).closest('.sl-coupon-chip');
				$chip.css('opacity', '0.5');

				$.ajax({
					type: 'POST',
					url: (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.wc_ajax_url ? wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'remove_coupon') : '/?wc-ajax=remove_coupon'),
					data: {
						security: (typeof wc_checkout_params !== 'undefined' ? wc_checkout_params.remove_coupon_nonce : ''),
						coupon: code
					},
					success: function () {
						$(document.body).trigger('update_checkout', { update_shipping_method: true });
					},
					error: function () {
						$(document.body).trigger('update_checkout', { update_shipping_method: true });
					}
				});
			});
		}
	};

	$(document).ready(function () {
		CheckoutController.init();
	});

})(jQuery);
