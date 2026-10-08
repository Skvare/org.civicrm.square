  /*jshint esversion: 8 */
  /**
   * JS Integration between CiviCRM & Square Web Payments SDK.
   *
   * Supports:
   *  - CiviCRM native contribution pages and event registration forms
   *  - Drupal Webform (webform_civicrm module) billing blocks
   *  - Backend contribution / event forms
   *
   * Architecture mirrors Stripe (civicrmStripe.js) and AuthNet (civicrmAuthNetAccept.js):
   *  - CRM.squarePayment  — shared form-utility object (equivalent to CRM.payment in mjwshared)
   *  - window.civicrmSquareHandleReload — reinitializes the card element when the
   *    billing block is injected or replaced (including webform AJAX loads)
   */
  (function ($, ts) {

    // ── Shared payment utilities ────────────────────────────────────────────────
    // Equivalent to the CRM.payment object provided by the mjwshared extension in
    // the Stripe/AuthNet ecosystem. We define it here so Square is self-contained.

    var payment = {
      form: null,
      submitButtons: null,
      scripts: {},

      /**
       * Sum visible line items on a webform or fall back to CiviCRM native total.
       */
      getTotalAmount: function() {
        var totalAmount = 0.0;
        if (this.getIsDrupalWebform()) {
          $('.line-item:visible', '#wf-crm-billing-items').each(function() {
            totalAmount += parseFloat($(this).data('amount'));
          });
          return totalAmount;
        }
        if (typeof calculateTotalFee === 'function') {
          return parseFloat(calculateTotalFee());
        }
        if (document.getElementById('totalTaxAmount') !== null) {
          return this.calculateTaxAmount();
        }
        if ($('#priceset [price]').length > 0) {
          $('#priceset [price]').each(function() {
            totalAmount += $(this).data('line_raw_total');
          });
          return totalAmount;
        }
        if (document.getElementById('total_amount')) {
          return parseFloat(document.getElementById('total_amount').value);
        }
        return totalAmount;
      },

      calculateTaxAmount: function() {
        var el = document.getElementById('totalTaxAmount');
        if (!el) return 0;
        var totalTaxAmount;
        if (el.textContent.length === 0) {
          totalTaxAmount = document.getElementById('total_amount').value;
        }
        else {
          var dPoint = (typeof separator !== 'undefined') ? separator : '.';
          var matcher = new RegExp('\\d{1,3}(' + dPoint.replace(/\W/g, '\\$&') + '\\d{0,2})?', 'g');
          totalTaxAmount = el.textContent.match(matcher).join('').replace(dPoint, '.');
        }
        totalTaxAmount = parseFloat(totalTaxAmount);
        return isNaN(totalTaxAmount) ? 0.0 : totalTaxAmount;
      },

      getCurrency: function(defaultCurrency) {
        if (this.form && this.form.querySelector('#currency')) {
          return this.form.querySelector('#currency').value;
        }
        return defaultCurrency;
      },

      /**
       * Are we on a Drupal webform?
       * Webform 7: .webform-client-form  Webform 8/9/10: .webform-submission-form
       */
      getIsDrupalWebform: function() {
        return this.form !== null && (
          this.form.classList.contains('webform-client-form') ||
          this.form.classList.contains('webform-submission-form')
        );
      },

      /**
       * Find the <form> element that contains the billing block.
       * Searches for well-known CiviCRM billing-block markers.
       */
      getBillingForm: function() {
        var billingFormID = $('div#crm-payment-js-billing-form-container').closest('form').attr('id');
        if (typeof billingFormID === 'undefined' || !billingFormID.length) {
          billingFormID = $('input[name=hidden_processor]').closest('form').prop('id');
        }
        if (typeof billingFormID === 'undefined' || !billingFormID.length) {
          billingFormID = $('div#billing-payment-block').closest('form').prop('id');
        }
        if (typeof billingFormID === 'undefined' || !billingFormID.length) {
          this.debugging('squarePayment', 'no billing form found');
          this.form = null;
          return null;
        }
        this.form = document.getElementById(billingFormID);
        return this.form;
      },

      /**
       * Return the submit buttons relevant to this billing form.
       * Webforms use different button classes than CiviCRM native forms.
       */
      getBillingSubmit: function() {
        if (this.getIsDrupalWebform()) {
          this.submitButtons = this.form.querySelectorAll('[type="submit"].webform-submit');
          if (this.submitButtons.length === 0) {
            // Drupal 8/9/10 webform
            this.submitButtons = this.form.querySelectorAll('[type="submit"].webform-button--submit');
          }
        }
        else {
          this.submitButtons = this.form.querySelectorAll('[type="submit"].validate');
        }
        return this.submitButtons;
      },

      getPaymentProcessorSelectorValue: function() {
        var sel = this.form.querySelector('input[name="payment_processor_id"]:checked');
        if (sel) return parseInt(sel.value);
        sel = this.form.querySelector('select[name="payment_processor_id"]');
        if (sel) return parseInt(sel.value);
        return null;
      },

      /**
       * Return true when an AJAX URL is a CiviCRM payment-form request.
       * Used to detect when the webform billing block has been refreshed.
       */
      isAJAXPaymentForm: function(url) {
        var patterns = [
          '(\\/|%2F)payment(\\/|%2F)form',
          '(\\/|%2F)contact(\\/|%2F)view(\\/|%2F)participant',
          '(\\/|%2F)contact(\\/|%2F)view(\\/|%2F)membership',
          '(\\/|%2F)contact(\\/|%2F)view(\\/|%2F)contribution',
        ];
        var basePage = (CRM.config && CRM.config.isFrontend && CRM.vars.payment && CRM.vars.payment.basePage)
          ? CRM.vars.payment.basePage : null;
        for (var i = 0; i < patterns.length; i++) {
          if (basePage && url.match(basePage + patterns[i])) return true;
          if (url.match('civicrm' + patterns[i])) return true;
        }
        return false;
      },

      resetBillingFieldsRequiredForJQueryValidate: function() {
        $('div#priceset input[type="checkbox"], fieldset.crm-profile input[type="checkbox"], #on-behalf-block input[type="checkbox"]').each(function() {
          if ($(this).attr('data-name') !== undefined) {
            $(this).attr('name', $(this).attr('data-name'));
          }
        });
      },

      setBillingFieldsRequiredForJQueryValidate: function() {
        $('div.label span.crm-marker').each(function() {
          $(this).closest('div').next('div').find('input[type="checkbox"]').addClass('required');
        });
        $('div#priceset input[type="checkbox"], fieldset.crm-profile input[type="checkbox"], #on-behalf-block input[type="checkbox"]').each(function() {
          var name = $(this).attr('name');
          $(this).attr('data-name', name);
          $(this).attr('name', name.replace('[' + name.split('[').pop(), ''));
        });
      },

      /**
       * Drupal webform needs an "op" hidden field to know which page action fired.
       */
      addDrupalWebformActionElement: function(submitAction) {
        var hiddenInput = document.getElementById('action') || document.createElement('input');
        hiddenInput.setAttribute('type', 'hidden');
        hiddenInput.setAttribute('name', 'op');
        hiddenInput.setAttribute('id', 'action');
        hiddenInput.setAttribute('value', submitAction);
        this.form.appendChild(hiddenInput);
      },

      doStandardFormSubmit: function() {
        for (var i = 0; i < this.submitButtons.length; ++i) {
          this.submitButtons[i].setAttribute('disabled', true);
        }
        this.resetBillingFieldsRequiredForJQueryValidate();
        this.form.submit();
      },

      validateReCaptcha: function() {
        if (typeof grecaptcha === 'undefined') return true;
        if ($(this.form).find('[name=g-recaptcha-response]').length === 0) return true;
        if ($(this.form).find('[name=g-recaptcha-response]').val().length > 0) return true;
        this.swalFire({ icon: 'warning', text: '', title: ts('Please complete the reCaptcha') }, '.recaptcha-section', true);
        this.triggerEvent('crmBillingFormNotValid');
        this.form.dataset.submitted = 'false';
        return false;
      },

      validateCiviDiscount: function() {
        if ($('input#discountcode').length &&
            $('input#discountcode').val().length > 0 &&
            $('input#discountcode').attr('discount-applied') != 1) {
          this.swalFire({ icon: 'error', text: ts('Please apply the Discount Code or clear the Discount Code text-field'), title: '' }, '#crm-container', true);
          this.triggerEvent('crmBillingFormNotValid');
          this.form.dataset.submitted = 'false';
          return false;
        }
        return true;
      },

      validateForm: function() {
        if (($(this.form).valid() === false) || $(this.form).data('crmBillingFormValid') === false) {
          this.debugging('squarePayment', 'form not valid');
          this.swalFire({ icon: 'error', text: ts('Please check and fill in all required fields!'), title: '' }, '#crm-container', true);
          this.triggerEvent('crmBillingFormNotValid');
          this.form.dataset.submitted = 'false';
          return false;
        }
        return true;
      },

      addHandlerNonPaymentSubmitButtons: function() {
        var self = this;
        var nonPaymentSubmitButtons = this.form.querySelectorAll(
          '[type="submit"][formnovalidate="1"], ' +
          '[type="submit"][formnovalidate="formnovalidate"], ' +
          '[type="submit"].cancel, ' +
          '[type="submit"].webform-previous'
        );
        for (var i = 0; i < nonPaymentSubmitButtons.length; ++i) {
          nonPaymentSubmitButtons[i].addEventListener('click', function() {
            self.form.dataset.submitdontprocess = 'true';
          });
        }
      },

      addSupportForCiviDiscount: function() {
        var self = this;
        var els = this.form.querySelectorAll('input#discountcode');
        for (var i = 0; i < els.length; ++i) {
          els[i].addEventListener('keydown', function(event) {
            if (event.code === 'Enter') {
              event.preventDefault();
              self.form.dataset.submitdontprocess = 'true';
            }
          });
        }
      },

      displayError: function(errorMessage, notify) {
        this.debugging('squarePayment', 'error: ' + errorMessage);
        var errorElement = document.getElementById('square-card-errors');
        if (errorElement) {
          errorElement.style.display = 'block';
          errorElement.textContent = errorMessage;
        }
        if (this.form) {
          this.form.dataset.submitted = 'false';
        }
        if (this.submitButtons) {
          for (var i = 0; i < this.submitButtons.length; ++i) {
            this.submitButtons[i].removeAttribute('disabled');
          }
        }
        this.triggerEvent('crmBillingFormNotValid');
        if (notify) {
          this.swalFire({ icon: 'error', text: errorMessage, title: '' }, '#crm-container', true);
        }
      },

      swalFire: function(parameters, scrollToElement, fallBackToAlert) {
        if (typeof Swal === 'function') {
          if (scrollToElement && scrollToElement.length > 0) {
            var $el = $(scrollToElement);
            if ($el.length) {
              parameters.didClose = function() { window.scrollTo($el.position()); };
            }
          }
          Swal.fire(parameters);
        }
        else if (fallBackToAlert) {
          window.alert((parameters.title || '') + ' ' + (parameters.text || ''));
        }
      },

      swalClose: function() {
        if (typeof Swal === 'function') Swal.close();
      },

      triggerEvent: function(event, scriptName) {
        var triggerNow = true;
        if (typeof scriptName !== 'undefined' && event === 'crmBillingFormReloadComplete') {
          if (this.scripts[scriptName]) {
            this.scripts[scriptName].reloadComplete = true;
          }
          $.each(this.scripts, function(name, obj) {
            if (obj.reloadComplete !== true) {
              triggerNow = false;
              return false;
            }
          });
        }
        if (triggerNow && this.form) {
          $(this.form).trigger(event);
        }
      },

      registerScript: function(scriptName) {
        this.scripts[scriptName] = { reloadComplete: false };
      },

      debugging: function(scriptName, errorCode) {
        if (typeof CRM.vars !== 'undefined' &&
            typeof CRM.vars.payment !== 'undefined' &&
            Boolean(CRM.vars.payment.jsDebug) === true) {
          console.log(new Date().toISOString() + ' ' + scriptName + ': ' + errorCode);
        }
      }
    };

    if (typeof CRM.squarePayment === 'undefined') {
      CRM.squarePayment = payment;
    }
    else {
      $.extend(CRM.squarePayment, payment);
    }

    // ── Square processor script ─────────────────────────────────────────────────

    var script = {
      name: 'square',
      card: null,
      sdkPromise: null,
      initializing: false,
      // The postal code in the card form, as last reported by Square.
      cardPostalCode: '',
      // The value this script last copied into the billing postal code field.
      autofilledPostalCode: null,
      billingPostalCodePattern: /billing_postal_code(-\d+)?\]?$/,

      debugging: function(msg) {
        CRM.squarePayment.debugging(script.name, msg);
      },

      getConfig: function() {
        var cfg = (typeof CRM.vars !== 'undefined' && CRM.vars.orgUschessSquare) || {};
        return {
          appId:       cfg.applicationId || window.squareApplicationId || '',
          locationId:  cfg.locationId    || window.squareLocationId    || '',
          isSandbox:   !!(cfg.isSandbox  || window.squareIsSandbox),
          processorId: cfg.id ? parseInt(cfg.id) : null,
          currency:    cfg.currency || 'USD',
          countryIsoCodes: cfg.countryIsoCodes || {},
        };
      },

      /**
       * Value of the first form field whose name matches, if any.
       *
       * CiviCRM names billing fields e.g. billing_city-5 (5 being the billing
       * location type); webforms wrap names in submitted[...].
       */
      fieldValue: function(pattern) {
        var el = script.findField(pattern);
        if (!el) return '';
        if (el.tagName === 'SELECT') {
          return el.selectedIndex > 0 || el.value ? { value: el.value, text: (el.options[el.selectedIndex] || {}).text || '' } : '';
        }
        return (el.value || '').trim();
      },

      /**
       * The first form field whose name matches (a radio or checkbox only if checked), if any.
       */
      findField: function(pattern) {
        var form = CRM.squarePayment.form;
        if (!form) return null;
        for (var i = 0; i < form.elements.length; i++) {
          var el = form.elements[i];
          if (!el.name || !pattern.test(el.name)) continue;
          if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) continue;
          return el;
        }
        return null;
      },

      /**
       * Whether this checkout sets up a recurring payment.
       */
      isRecurring: function() {
        var form = CRM.squarePayment.form;
        if (!form) return false;
        if ($(form).find('input[name="is_recur"]:checked, input[type="hidden"][name="is_recur"][value="1"]').length) return true;
        if ($(form).find('input[name="auto_renew"]:checked, input[type="hidden"][name="auto_renew"][value="1"]').length) return true;
        // Webform CiviCRM: a frequency other than "one-time" (0).
        var frequency = script.fieldValue(/contribution_frequency_unit\]?$/);
        frequency = typeof frequency === 'object' ? frequency.value : frequency;
        return !!frequency && frequency !== '0';
      },

      /**
       * Buyer details Square uses to verify the buyer (Strong Customer Authentication).
       *
       * Square performs the verification while tokenizing, and the token
       * carries it: nothing more is sent to CiviCRM. A recurring checkout only
       * stores the card (Square's subscription charges it), so its intent is
       * STORE.
       */
      getVerificationDetails: function(totalAmount) {
        var cfg = script.getConfig();
        var text = function(value) {
          return typeof value === 'object' ? value.text : value;
        };
        var contact = {};
        var givenName = script.fieldValue(/(^|\[|_)billing_first_name(\]|$)/) || script.fieldValue(/first_name\]?$/);
        var familyName = script.fieldValue(/(^|\[|_)billing_last_name(\]|$)/) || script.fieldValue(/last_name\]?$/);
        var email = script.fieldValue(/^email-(\d+|Primary)$/) || script.fieldValue(/email(-\d+)?\]?$/);
        var street = script.fieldValue(/billing_street_address(-\d+)?\]?$/);
        var street2 = script.fieldValue(/billing_supplemental_address_1(-\d+)?\]?$/);
        var city = script.fieldValue(/billing_city(-\d+)?\]?$/);
        var state = script.fieldValue(/billing_state_province(_id)?(-\d+)?\]?$/);
        var postalCode = script.fieldValue(script.billingPostalCodePattern);
        var country = script.fieldValue(/billing_country(_id)?(-\d+)?\]?$/);
        if (givenName) contact.givenName = givenName;
        if (familyName) contact.familyName = familyName;
        if (email) contact.email = email;
        if (street) contact.addressLines = street2 ? [street, street2] : [street];
        if (city) contact.city = city;
        if (state && text(state)) contact.state = text(state);
        if (postalCode) contact.postalCode = postalCode;
        if (country) {
          var countryCode = typeof country === 'object' ? cfg.countryIsoCodes[country.value] : country;
          if (countryCode && /^[A-Za-z]{2}$/.test(countryCode)) contact.countryCode = countryCode.toUpperCase();
        }
        return {
          amount: Number(totalAmount || 0).toFixed(2),
          billingContact: contact,
          currencyCode: CRM.squarePayment.getCurrency(cfg.currency),
          intent: script.isRecurring() ? 'STORE' : 'CHARGE',
          customerInitiated: true,
          sellerKeyedIn: false
        };
      },

      /**
       * Options for Square's card form: its postal code starts as the billing address's.
       *
       * A logged-in donor's billing address is often filled in already.
       */
      getCardOptions: function() {
        var postalCode = script.toCardPostalCode(script.fieldValue(script.billingPostalCodePattern));
        return postalCode ? { postalCode: postalCode } : null;
      },

      /**
       * A billing postal code as the card form takes it: a US ZIP+4 is cut to its 5-digit ZIP.
       */
      toCardPostalCode: function(postalCode) {
        postalCode = (postalCode || '').trim();
        return /^\d{5}-?\d{4}$/.test(postalCode) ? postalCode.slice(0, 5) : postalCode;
      },

      /**
       * Update the card form's postal code when the donor changes the billing address's.
       *
       * A billing postal code that is cleared leaves the card form's as it is.
       */
      onBillingPostalCodeChanged: function(billingPostalCode) {
        var postalCode = script.toCardPostalCode(billingPostalCode);
        var card = script.card;
        if (!card || !postalCode || script.postalCodesMatch(postalCode, script.cardPostalCode)) return;
        Promise.resolve()
          .then(function() {
            return card.configure({ postalCode: postalCode });
          })
          .then(function() {
            // Square may also report it through postalCodeChanged.
            if (script.card === card) {
              script.cardPostalCode = postalCode;
            }
          })
          .catch(function(err) {
            // submit() still stops a mismatch.
            script.debugging('could not update the card form postal code: ' + (err && err.message || err));
          });
      },

      /**
       * Fill in the billing postal code from the card form's, as the donor types it.
       *
       * The card form comes before the billing address on CiviCRM's forms, so
       * the billing postal code is often still empty. One the donor (or
       * CiviCRM) filled in is never overwritten: if it differs, submit() asks
       * the donor to correct one of them.
       */
      onCardPostalCodeChanged: function(postalCode) {
        script.cardPostalCode = (postalCode || '').trim();
        var field = script.findField(script.billingPostalCodePattern);
        if (!field || field.disabled || field.readOnly) return;
        var current = (field.value || '').trim();
        if (current === '' || current === script.autofilledPostalCode) {
          field.value = script.cardPostalCode;
          script.autofilledPostalCode = script.cardPostalCode;
        }
      },

      /**
       * Whether two postal codes are the same, ignoring case, spaces and
       * hyphens. US ZIP codes are compared by their first 5 digits, so a
       * ZIP+4 matches its ZIP.
       */
      postalCodesMatch: function(a, b) {
        var normalize = function(value) {
          return String(value || '').toUpperCase().replace(/[^0-9A-Z]/g, '');
        };
        a = normalize(a);
        b = normalize(b);
        var zip = /^\d{5}(\d{4})?$/;
        if (zip.test(a) && zip.test(b)) {
          return a.slice(0, 5) === b.slice(0, 5);
        }
        return a === b;
      },

      /**
       * The error to show when the card form's postal code differs from the billing address's.
       *
       * Square verifies the card against the postal code in the card form;
       * CiviCRM records the billing address. Both describe the card's billing
       * address, so they must agree.
       *
       * @return {string}
       *   Empty if they match, or either is blank.
       */
      getPostalCodeMismatchError: function(cardPostalCode) {
        var billingPostalCode = script.fieldValue(script.billingPostalCodePattern);
        if (!cardPostalCode || !billingPostalCode || script.postalCodesMatch(cardPostalCode, billingPostalCode)) {
          return '';
        }
        return ts('The ZIP/postal code entered with your card (%1) does not match the one in your billing address (%2). Please correct whichever is wrong.', {1: cardPostalCode, 2: billingPostalCode});
      },

      ensureSdkLoaded: function(isSandbox) {
        if (window.Square && window.Square.payments) return Promise.resolve();
        if (script.sdkPromise) return script.sdkPromise;

        var sdkUrl = isSandbox
          ? 'https://sandbox.web.squarecdn.com/v1/square.js'
          : 'https://web.squarecdn.com/v1/square.js';

        script.sdkPromise = new Promise(function(resolve, reject) {
          var existing = document.querySelector('script[src="' + sdkUrl + '"]');
          if (existing) {
            if (window.Square && window.Square.payments) { resolve(); return; }
            existing.addEventListener('load', resolve);
            existing.addEventListener('error', function() { reject(new Error('Square SDK load failed')); });
            return;
          }
          var s = document.createElement('script');
          s.src = sdkUrl;
          s.async = true;
          s.onload = resolve;
          s.onerror = function() { reject(new Error('Square SDK load failed')); };
          (document.head || document.documentElement).appendChild(s);
        });

        return script.sdkPromise;
      },

      notScriptProcessor: function() {
        script.debugging('payment processor is not Square, cleaning up');
        script.initializing = false;
        if (script.card) {
          try { script.card.destroy(); } catch(e) {}
          script.card = null;
        }
        var containerEl = document.getElementById('square-card-container');
        if (containerEl) {
          containerEl.innerHTML = '';
        }
        if (typeof CRM.vars !== 'undefined') {
          delete CRM.vars.orgUschessSquare;
        }
        if (CRM.squarePayment.submitButtons) {
          $(CRM.squarePayment.submitButtons).show();
        }
      },

      checkAndLoad: function() {
        if (script.initializing) {
          script.debugging('init already in progress, skipping');
          return;
        }
        if (typeof CRM.vars === 'undefined' || typeof CRM.vars.orgUschessSquare === 'undefined') {
          script.debugging('CRM.vars.orgUschessSquare not defined');
          return;
        }
        var cfg = script.getConfig();
        if (!cfg.appId || !cfg.locationId) {
          script.debugging('Square config missing applicationId or locationId');
          return;
        }

        script.initializing = true;

        // Destroy any previously mounted card instance before creating a new one.
        if (script.card) {
          try { script.card.destroy(); } catch(e) {}
          script.card = null;
        }

        // Empty the container so Square always starts with a clean slate.
        // This prevents the "card appears twice" issue when switching processors.
        var containerEl = document.getElementById('square-card-container');
        if (containerEl) {
          containerEl.innerHTML = '';
          containerEl.style.display = 'none';
        }

        script.ensureSdkLoaded(cfg.isSandbox)
          .then(function() {
            if (!window.Square || !window.Square.payments) {
              throw new Error('Square.payments API not available after SDK load');
            }
            var payments = window.Square.payments(cfg.appId, cfg.locationId);
            var cardOptions = script.getCardOptions();
            script.cardPostalCode = cardOptions ? cardOptions.postalCode : '';
            script.autofilledPostalCode = null;
            return (cardOptions ? payments.card(cardOptions) : payments.card()).then(function(c) {
              script.card = c;
              script.card.addEventListener('postalCodeChanged', function(cardInputEvent) {
                script.onCardPostalCodeChanged(cardInputEvent.detail && cardInputEvent.detail.postalCodeValue);
              });
              return script.card.attach('#square-card-container');
            });
          })
          .then(function() {
            script.initializing = false;
            var container = document.getElementById('square-card-container');
            if (container) container.style.display = 'block';
            script.doAfterElementsHaveLoaded();
          })
          .catch(function(err) {
            script.initializing = false;
            script.debugging('Square card init failed: ' + (err && err.message || err));
            var errEl = document.getElementById('square-card-errors');
            if (errEl) {
              errEl.textContent = ts('Unable to load secure card entry. Please try again later or contact support.');
              errEl.style.display = 'block';
            }
            script.triggerReloadFailed();
          });
      },

      doAfterElementsHaveLoaded: function() {
        CRM.squarePayment.setBillingFieldsRequiredForJQueryValidate();
        CRM.squarePayment.form.dataset.submitdontprocess = 'false';
        CRM.squarePayment.addHandlerNonPaymentSubmitButtons();

        var submitButtons = CRM.squarePayment.getBillingSubmit();
        for (var i = 0; i < submitButtons.length; ++i) {
          $(submitButtons[i]).off('click.square').on('click.square', submitButtonClick);
        }

        function submitButtonClick(clickEvent) {
          if (typeof CRM.vars === 'undefined' || typeof CRM.vars.orgUschessSquare === 'undefined') {
            return CRM.squarePayment.doStandardFormSubmit();
          }
          CRM.squarePayment.form.dataset.submitdontprocess = 'false';
          return script.submit(clickEvent);
        }

        CRM.squarePayment.addSupportForCiviDiscount();

        // The card form's postal code follows the billing address's. Bound to
        // the form, so it also covers billing fields shown or reloaded later.
        $(CRM.squarePayment.form).off('change.squarePostalCode').on('change.squarePostalCode', function(changeEvent) {
          var target = changeEvent.target;
          if (target && target.name && script.billingPostalCodePattern.test(target.name)) {
            script.onBillingPostalCodeChanged(target.value);
          }
        });

        // Webform-specific wiring. Namespaced, and unbound first, because the
        // billing block (and so this) is reloaded whenever the processor or
        // amount changes.
        if (CRM.squarePayment.getIsDrupalWebform()) {
          // Store which submit button was clicked so the op hidden field is set
          $('[type=submit]', CRM.squarePayment.form).off('click.squareWebform').on('click.squareWebform', function() {
            CRM.squarePayment.addDrupalWebformActionElement(this.value);
          });
          // Enter key on webform should also trigger our submit
          $(CRM.squarePayment.form).off('keydown.squareWebform').on('keydown.squareWebform', function(keydownEvent) {
            if (keydownEvent.key === 'Enter' && keydownEvent.target.tagName !== 'TEXTAREA') {
              CRM.squarePayment.addDrupalWebformActionElement(keydownEvent.target.value || '');
              script.submit(keydownEvent);
            }
          });
          $('#billingcheckbox:input').hide();
          $('label[for="billingcheckbox"]').hide();
        }

        var cardContainer = document.getElementById('square-card-container');
        if (cardContainer && cardContainer.children.length) {
          CRM.squarePayment.triggerEvent('crmBillingFormReloadComplete', script.name);
          CRM.squarePayment.triggerEvent('crmSquareBillingFormReloadComplete', script.name);
        }
        else {
          script.triggerReloadFailed();
        }
      },

      submit: async function(submitEvent) {
        submitEvent.preventDefault();
        script.debugging('submit handler');

        if (CRM.squarePayment.form.dataset.submitted === 'true') {
          return;
        }
        CRM.squarePayment.form.dataset.submitted = 'true';

        if (!CRM.squarePayment.validateCiviDiscount()) return false;
        if (!CRM.squarePayment.validateForm()) return false;
        if (!CRM.squarePayment.validateReCaptcha()) return false;

        if (typeof CRM.vars === 'undefined' || typeof CRM.vars.orgUschessSquare === 'undefined') {
          script.debugging('not a Square processor, submitting normally');
          return CRM.squarePayment.doStandardFormSubmit();
        }

        var cfg = script.getConfig();
        var chosenProcessorId = null;

        // Determine which processor the user has selected (matters when multiple processors exist)
        if (CRM.squarePayment.getIsDrupalWebform()) {
          var $wfProc = $('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]');
          if (!$wfProc.length) {
            // Single processor on form — treat it as ours
            chosenProcessorId = cfg.processorId;
          }
          else {
            var checkedWf = CRM.squarePayment.form.querySelector('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked');
            chosenProcessorId = checkedWf ? parseInt(checkedWf.value) : null;
          }
        }
        else {
          if ((CRM.squarePayment.form.querySelector('.crm-section.payment_processor-section') !== null) ||
              (CRM.squarePayment.form.querySelector('.crm-section.credit_card_info-section') !== null)) {
            var checkedProc = CRM.squarePayment.form.querySelector('input[name="payment_processor_id"]:checked');
            if (checkedProc) {
              chosenProcessorId = parseInt(checkedProc.value);
            }
          }
        }

        // Pay-later or no processor selected → standard submit
        if (chosenProcessorId === 0) {
          script.debugging('pay-later selected');
          return CRM.squarePayment.doStandardFormSubmit();
        }

        // Non-payment submit (e.g. discount "Apply" button) → skip tokenization
        if (CRM.squarePayment.form.dataset.submitdontprocess === 'true') {
          script.debugging('non-payment submit, skipping tokenization');
          return CRM.squarePayment.doStandardFormSubmit();
        }

        if (CRM.squarePayment.getIsDrupalWebform()) {
          // Billing block hidden → not a payment step
          if ($('#billing-payment-block').is(':hidden')) {
            script.debugging('billing block hidden on webform');
            return CRM.squarePayment.doStandardFormSubmit();
          }
          var $procFields = $('[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]');
          if ($procFields.length) {
            var checkedVal = $procFields.filter(':checked').val();
            if (checkedVal === '0' || parseInt(checkedVal) === 0) {
              script.debugging('no payment processor selected on webform');
              return CRM.squarePayment.doStandardFormSubmit();
            }
          }
        }

        var totalAmount = CRM.squarePayment.getTotalAmount();
        if (totalAmount === 0.0) {
          script.debugging('zero amount, standard submit');
          return CRM.squarePayment.doStandardFormSubmit();
        }

        // Disable buttons to prevent double-clicks
        var submitButtons = CRM.squarePayment.submitButtons;
        for (var i = 0; i < submitButtons.length; ++i) {
          submitButtons[i].setAttribute('disabled', true);
        }

        if (!script.card) {
          script.debugging('card element not initialized');
          CRM.squarePayment.form.dataset.submitted = 'false';
          for (var j = 0; j < submitButtons.length; ++j) {
            submitButtons[j].removeAttribute('disabled');
          }
          CRM.squarePayment.displayError(ts('Secure card entry is not ready. Please wait a moment and try again.'), true);
          return false;
        }

        // Checked before tokenizing too, so the donor is not taken through
        // buyer verification only to be asked to correct the postal code.
        var postalCodeError = script.getPostalCodeMismatchError(script.cardPostalCode);
        if (postalCodeError) {
          CRM.squarePayment.displayError(postalCodeError, true);
          return false;
        }

        try {
          var result = await script.card.tokenize(script.getVerificationDetails(totalAmount));
          if (!result || result.status !== 'OK') {
            var message = ts('Your card could not be processed. Please check your details.');
            if (result && result.errors && result.errors.length) {
              message = result.errors[0].message || message;
            }
            CRM.squarePayment.displayError(message, true);
            return false;
          }

          // The postal code the token carries is the one that counts.
          var billing = (result.details && result.details.billing) || {};
          postalCodeError = script.getPostalCodeMismatchError(billing.postalCode);
          if (postalCodeError) {
            CRM.squarePayment.displayError(postalCodeError, true);
            return false;
          }

          // Write token into the hidden field that the PHP processor reads
          var tokenField = CRM.squarePayment.form.querySelector('#square_payment_token') ||
                           CRM.squarePayment.form.querySelector('[name="square_payment_token"]');
          if (!tokenField) {
            tokenField = document.createElement('input');
            tokenField.setAttribute('type', 'hidden');
            tokenField.setAttribute('name', 'square_payment_token');
            tokenField.setAttribute('id', 'square_payment_token');
            CRM.squarePayment.form.appendChild(tokenField);
          }
          tokenField.value = result.token;

          CRM.squarePayment.resetBillingFieldsRequiredForJQueryValidate();
          CRM.squarePayment.form.submit();
        }
        catch(e) {
          CRM.squarePayment.displayError(ts('Unexpected error processing your card. Please try again.'), true);
          CRM.squarePayment.form.dataset.submitted = 'false';
          return false;
        }
      },

      triggerReloadFailed: function() {
        CRM.squarePayment.triggerEvent('crmBillingFormReloadFailed');
        var errEl = document.getElementById('square-card-errors');
        if (errEl) {
          errEl.textContent = ts('Could not load payment element. Is there a problem with your network connection?');
          errEl.style.display = 'block';
        }
      }
    };

    // ── Bootstrap ───────────────────────────────────────────────────────────────

    if (CRM.squarePayment.hasOwnProperty(script.name)) {
      // Already loaded — just re-run HandleReload in case the billing block was replaced
      if (window.civicrmSquareHandleReload) {
        window.civicrmSquareHandleReload();
      }
      return;
    }

    var crmPaymentObject = {};
    crmPaymentObject[script.name] = script;
    $.extend(CRM.squarePayment, crmPaymentObject);

    CRM.squarePayment.registerScript(script.name);

    // Re-init when the billing block is loaded via AJAX (webforms, backend switches)
    $(document).ajaxComplete(function(event, xhr, settings) {
      if (CRM.squarePayment.isAJAXPaymentForm(settings.url)) {
        CRM.squarePayment.debugging(script.name, 'triggered via ajaxComplete');
        load();
      }
    });

    document.addEventListener('DOMContentLoaded', function() {
      CRM.squarePayment.debugging(script.name, 'DOMContentLoaded');
      load();
    });

    function load() {
      if (window.civicrmSquareHandleReload) {
        CRM.squarePayment.debugging(script.name, 'calling civicrmSquareHandleReload');
        window.civicrmSquareHandleReload();
      }
    }

    /**
     * (Re-)initialize Square for the current billing block.
     *
     * Called on DOMContentLoaded and whenever the billing block is reloaded via
     * AJAX (e.g. processor switch on a native form, or webform billing step load).
     */
    window.civicrmSquareHandleReload = function() {
      CRM.squarePayment.scriptName = script.name;
      CRM.squarePayment.debugging(script.name, 'HandleReload');

      // Reset per-reload state so triggerEvent fires correctly after reload
      $.each(CRM.squarePayment.scripts, function(name, obj) {
        obj.reloadComplete = false;
      });

      CRM.squarePayment.form = CRM.squarePayment.getBillingForm();
      if (!CRM.squarePayment.form) {
        CRM.squarePayment.debugging(script.name, 'no billing form found');
        return;
      }

      $(CRM.squarePayment.getBillingSubmit()).show();

      var cardContainer = document.getElementById('square-card-container');
      if (cardContainer) {
        // Always call checkAndLoad — it clears the container and destroys any
        // previous card instance before mounting a fresh one. This prevents the
        // "card appears twice" issue when switching processors and coming back.
        CRM.squarePayment.debugging(script.name, 'mounting Square card element');
        script.checkAndLoad();
      }
      else {
        // No card container → this form uses a different processor
        script.notScriptProcessor();
        CRM.squarePayment.triggerEvent('crmBillingFormReloadComplete', script.name);
      }
    };

  }(CRM.$, CRM.ts('org.uschess.square')));
