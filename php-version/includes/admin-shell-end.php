  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Instant theme toggle — no page redirect, persists via cookie for 1 year.
function toggleAdmTheme(ev){
  if(ev) ev.preventDefault();
  var html = document.documentElement;
  var cur  = html.getAttribute('data-bs-theme') || 'light';
  var next = cur === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-bs-theme', next);
  document.cookie = 'adm_mode=' + next + '; path=/; max-age=' + (365*86400) + '; SameSite=Lax';
  var icon = document.getElementById('admThemeIcon');
  if (icon) icon.className = 'bi ' + (next === 'dark' ? 'bi-sun' : 'bi-moon-stars');
}
</script>
</body>
</html>
