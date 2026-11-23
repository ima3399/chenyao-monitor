<?php
if (!defined('APP_NAME')) {
    http_response_code(404);
    exit;
}
?>
        </div>
        <footer class="app-footer">
            <span>© 2026 <?= e(APP_COMPANY) ?> · <?= e(APP_NAME) ?></span>
            <span>v<?= e(APP_VERSION) ?> · 华东生产域 · 授权访问</span>
        </footer>
    </div>
</div>
<div class="nav-scrim" id="navScrim"></div>
<script src="/assets/js/app.js"></script>
</body>
</html>
