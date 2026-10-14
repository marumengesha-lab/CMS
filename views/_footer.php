  </main>
</div>
<footer class="main" style="padding-top:0">
  <p class="help no-print" style="text-align:center">
    <?= e((string) config('app.name')) ?> &middot; multi-level RBAC reference implementation &middot;
    scope <code><?= e(Auth::scope()->label()) ?></code> &middot;
    <a href="<?= e(url('index.php')) ?>">Dashboard</a>
  </p>
</footer>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
