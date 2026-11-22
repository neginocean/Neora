(function () {
	'use strict';

	const initTestSms = function () {
		const testBtn = document.getElementById('neora-send-test-sms');
		const phoneInput = document.getElementById('neora-test-sms-phone');
		const countrySelect = document.getElementById('neora-test-sms-country');
		const feedbackEl = document.getElementById('neora-test-sms-feedback');

		if (!testBtn || !phoneInput || !feedbackEl || typeof window.neoraAdminData === 'undefined') {
			return;
		}

		const showFeedback = function (message, isSuccess) {
			feedbackEl.textContent = message;
			feedbackEl.classList.remove('is-success', 'is-error', 'is-visible');
			feedbackEl.classList.add(isSuccess ? 'is-success' : 'is-error', 'is-visible');
		};

		testBtn.addEventListener('click', async function () {
			const mobile = phoneInput.value.trim();
			if (!mobile) {
				showFeedback(window.neoraAdminData.i18n.enterMobile, false);
				phoneInput.focus();
				return;
			}

			testBtn.disabled = true;
			const originalLabel = testBtn.textContent;
			testBtn.textContent = window.neoraAdminData.i18n.sending;

			try {
				const body = new URLSearchParams();
				body.set('action', 'neora_test_sms');
				body.set('nonce', window.neoraAdminData.testSmsNonce);
				body.set('mobile', mobile);
				if (countrySelect && countrySelect.value) {
					body.set('country', countrySelect.value);
				}

				const response = await fetch(window.neoraAdminData.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
					},
					body: body.toString(),
				});

				const payload = await response.json();

				if (payload && payload.success) {
					showFeedback(payload.data.message, true);
				} else {
					const errMsg =
						(payload && payload.data && payload.data.message) ||
						window.neoraAdminData.i18n.genericError;
					showFeedback(errMsg, false);
				}
			} catch (err) {
				showFeedback(window.neoraAdminData.i18n.genericError, false);
			} finally {
				testBtn.disabled = false;
				testBtn.textContent = originalLabel;
			}
		});
	};

	const initBrandingControls = function () {
		const colorPicker = document.getElementById('neora_general_brand_color_picker');
		const colorHex = document.getElementById('neora_general_brand_color');
		const resetColorBtn = document.getElementById('neora-reset-brand-color');

		if (colorPicker && colorHex) {
			colorPicker.addEventListener('input', function () {
				colorHex.value = colorPicker.value.toLowerCase();
			});

			colorHex.addEventListener('input', function () {
				const value = colorHex.value.trim();
				if (/^#[0-9a-fA-F]{6}$/.test(value)) {
					colorPicker.value = value.toLowerCase();
				}
			});

			if (resetColorBtn) {
				resetColorBtn.addEventListener('click', function () {
					const fallback =
						(window.neoraAdminData && window.neoraAdminData.defaultColor) || '#4f46e5';
					colorPicker.value = fallback;
					colorHex.value = fallback;
				});
			}
		}

		const logoInput = document.getElementById('neora_general_logo_url');
		const logoPreview = document.getElementById('neora-logo-preview');
		const selectLogoBtn = document.getElementById('neora-select-logo-btn');
		const removeLogoBtn = document.getElementById('neora-remove-logo-btn');

		if (!logoInput || !logoPreview) {
			return;
		}

		const updateLogoPreview = function (url) {
			const trimmed = (url || '').trim();
			while (logoPreview.firstChild) {
				logoPreview.removeChild(logoPreview.firstChild);
			}

			if (/^https?:\/\/.+/i.test(trimmed)) {
				const img = document.createElement('img');
				img.src = trimmed;
				img.alt = '';
				logoPreview.appendChild(img);
				logoPreview.classList.add('has-logo');
			} else {
				logoPreview.classList.remove('has-logo');
			}

			if (removeLogoBtn) {
				removeLogoBtn.hidden = '' === trimmed;
			}
		};

		logoInput.addEventListener('input', function () {
			updateLogoPreview(logoInput.value);
		});

		if (removeLogoBtn) {
			removeLogoBtn.addEventListener('click', function () {
				logoInput.value = '';
				updateLogoPreview('');
			});
		}

		if (selectLogoBtn && typeof window.wp !== 'undefined' && window.wp.media) {
			let mediaFrame = null;
			selectLogoBtn.addEventListener('click', function () {
				if (mediaFrame) {
					mediaFrame.open();
					return;
				}

				const i18n = (window.neoraAdminData && window.neoraAdminData.i18n) || {};
				mediaFrame = window.wp.media({
					title: i18n.selectLogoTitle || 'Select Brand Logo',
					button: { text: i18n.useThisLogo || 'Use this logo' },
					library: { type: 'image' },
					multiple: false,
				});

				mediaFrame.on('select', function () {
					const selection = mediaFrame.state().get('selection').first();
					const attachment = selection ? selection.toJSON() : null;
					if (attachment && attachment.url) {
						logoInput.value = attachment.url;
						updateLogoPreview(attachment.url);
					}
				});

				mediaFrame.open();
			});
		}
	};

	const initSmsQueueControls = function () {
		const card = document.getElementById('neora-sms-queue-card');
		if (!card || typeof window.neoraAdminData === 'undefined') {
			return;
		}

		const feedbackEl = document.getElementById('neora-sms-queue-feedback');
		const nonce = card.getAttribute('data-nonce') || '';
		const buttons = card.querySelectorAll('[data-queue-action]');

		const showFeedback = function (message, isSuccess) {
			if (!feedbackEl) {
				return;
			}
			feedbackEl.textContent = message;
			feedbackEl.classList.remove('is-success', 'is-error', 'is-visible');
			feedbackEl.classList.add(isSuccess ? 'is-success' : 'is-error', 'is-visible');
		};

		const updateStats = function (stats) {
			if (!stats) {
				return;
			}
			['pending', 'completed', 'failed'].forEach(function (key) {
				const el = document.getElementById('neora-queue-' + key);
				if (el && typeof stats[key] !== 'undefined') {
					el.textContent = String(stats[key]);
				}
			});
		};

		buttons.forEach(function (btn) {
			btn.addEventListener('click', async function () {
				const queueAction = btn.getAttribute('data-queue-action') || '';
				if (!queueAction) {
					return;
				}

				btn.disabled = true;
				try {
					const body = new URLSearchParams();
					body.set('action', 'neora_sms_queue_action');
					body.set('nonce', nonce);
					body.set('queue_action', queueAction);

					const response = await fetch(window.neoraAdminData.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
						},
						body: body.toString(),
					});

					const payload = await response.json();
					if (payload && payload.success) {
						updateStats(payload.data && payload.data.stats);
						showFeedback((payload.data && payload.data.message) || '', true);
					} else {
						const errMsg =
							(payload && payload.data && payload.data.message) ||
							window.neoraAdminData.i18n.genericError;
						showFeedback(errMsg, false);
					}
				} catch (err) {
					showFeedback(window.neoraAdminData.i18n.genericError, false);
				} finally {
					btn.disabled = false;
				}
			});
		});
	};

	document.addEventListener('DOMContentLoaded', function () {
		initTestSms();
		initSmsQueueControls();
		initBrandingControls();
	});
})();