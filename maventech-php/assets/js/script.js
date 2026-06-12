// Sidebar toggle (mobile)
document.addEventListener('DOMContentLoaded', function () {
  var btn = document.getElementById('sidebarToggle');
  var sb  = document.getElementById('sidebar');
  if (btn && sb) {
    btn.addEventListener('click', function () { sb.classList.toggle('open'); });
  }

  // Confirm before delete
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.dataset.confirm || 'Are you sure?')) e.preventDefault();
    });
  });

  // Auto-dismiss alerts
  setTimeout(function () {
    document.querySelectorAll('.alert .btn-close').forEach(function (b) { b.click(); });
  }, 5000);
});
