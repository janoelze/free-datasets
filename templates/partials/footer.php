<?php
declare(strict_types=1);
/** Closes main + shell. Vars: $catalog */
?>
    <footer class="colophon">
      <p><?= e($catalog->count()) ?> datasets, each one JSON file validated against a
        <a href="<?= e(url('/api/v1/schema')) ?>">JSON Schema</a>.
        This site is also a free, open, read-only <a href="<?= e(url('/api')) ?>">REST API</a> —
        no key, CORS enabled. Corrections and additions are welcome on
        <a href="https://github.com/janoelze/free-datasets">GitHub</a>.</p>
    </footer>
  </main>
</div>
</body>
</html>
