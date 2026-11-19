<?php

declare(strict_types=1);

$year = date('Y');
?>
        </div>
    </main>
    <footer class="footer">
        <div class="shell footer__inner">
            <span>Gestión de usuarios</span>
            <span>Politécnico Internacional &copy; <?= escape($year) ?></span>
        </div>
    </footer>
    <script src="<?= escape(asset('js/app.js')) ?>" defer></script>
</body>

</html>
