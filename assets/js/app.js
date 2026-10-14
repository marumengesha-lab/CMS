/**
 * Small progressive-enhancement layer. The application works without it:
 * every action is a normal HTML form post. This file only adds niceties.
 */
(function () {
  'use strict';

  // Confirm before destructive actions.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }
    // Prevent double submits.
    var submit = form.querySelector('button[type="submit"]');
    if (submit) {
      setTimeout(function () { submit.disabled = true; }, 0);
      setTimeout(function () { submit.disabled = false; }, 4000);
    }
  });

  // Auto-submit filter selects.
  document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () {
      if (select.form) { select.form.submit(); }
    });
  });

  // Copy-to-clipboard buttons.
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var text = button.getAttribute('data-copy');
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function () {
          var original = button.textContent;
          button.textContent = 'Copied';
          setTimeout(function () { button.textContent = original; }, 1200);
        });
      }
    });
  });

  // Toggle all checkboxes in the permission matrix.
  document.querySelectorAll('[data-toggle-all]').forEach(function (master) {
    master.addEventListener('change', function () {
      var scope = document.querySelectorAll('[data-toggle-target="' + master.getAttribute('data-toggle-all') + '"]');
      scope.forEach(function (box) { box.checked = master.checked; });
    });
  });
})();
