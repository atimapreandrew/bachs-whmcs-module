(function () {
  'use strict';
  document.querySelectorAll('.bachs-admin .bachs-form').forEach(function (form) {
    form.addEventListener('submit', function () {
      if (form.dataset.submitting) return;
      form.dataset.submitting = '1';
      var button = form.querySelector('button[type=submit]'); if (button) button.disabled = true;
      var status = form.querySelector('[role=status]'); if (status) status.textContent = 'Processing…';
    });
  });
  document.querySelectorAll('[data-bachs-download]').forEach(function (button) {
    button.addEventListener('click', function () {
      var value = button.parentNode.querySelector('textarea').value;
      var blob = new Blob([value], {type:'application/json'}); var url = URL.createObjectURL(blob);
      var anchor = document.createElement('a'); anchor.href = url; anchor.download = 'bachs-redacted-diagnostics.json'; anchor.click();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    });
  });
})();
