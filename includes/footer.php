<?php
/**
 * DJ CRM Software — Page Footer
 * Closes layouts and loads scripts
 */
if (!defined('YBT_ROOT')) exit;

$isPublicPage = $isPublicPage ?? false;
?>
<?php if (!$isPublicPage): ?>
            </div><!-- /.content-container -->
        </main><!-- /.main-content -->

        <footer class="app-footer">
            <div class="footer-left">
                <span>&copy; <?= date('Y') ?> <strong><?= e(APP_NAME) ?></strong>. All rights reserved.</span>
            </div>
            <div class="footer-right">
                <span class="text-muted">v<?= e(APP_VERSION) ?></span>
                <span class="text-separator">•</span>
                <a href="<?= url('docs/user-manual.md') ?>" target="_blank" class="text-muted">Help & Docs</a>
            </div>
        </footer>
    </div><!-- /.main-wrapper -->
</div><!-- /.app-shell -->
<?php endif; ?>

<!-- Chart.js for visualizations (Local with CDN fallback) -->
<script src="<?= asset('js/chart.umd.min.js') ?>"></script>
<script>if (!window.Chart) { document.write('<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"><\/script>'); }</script>

<!-- Application Scripts -->
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('js/tables.js') ?>"></script>

<!-- Page Specific Scripts -->
<?php if (!empty($pageScripts) && is_array($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
        <script src="<?= asset('js/' . ltrim($script, '/')) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Floating WhatsApp Support Button -->
<a href="https://wa.me/<?= e(WHATSAPP_SUPPORT_NUMBER) ?>?text=<?= urlencode('Hello DJ CRM Support, I need assistance with my software.') ?>" 
   target="_blank" 
   class="floating-whatsapp-btn" 
   title="Chat with DJ CRM Support on WhatsApp"
   aria-label="Chat on WhatsApp"
   style="position: fixed; bottom: 20px; right: 20px; z-index: 998; width: 48px; height: 48px; border-radius: 50%; background: #25D366; color: #FFFFFF; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(37, 211, 102, 0.4); text-decoration: none; font-size: 1.6rem; transition: transform 0.2s ease, box-shadow 0.2s ease;">
    <i class="ti ti-brand-whatsapp"></i>
</a>
</body>
</html>
