</div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // Confirm delete function
    function confirmDelete(url, itemName) {
        if (confirm('Are you sure you want to delete ' + itemName + '? This action cannot be undone.')) {
            window.location.href = url;
        }
    }
    
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    </script>

    <?php if (!empty($_SESSION['stock_alert'])): ?>
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <?php foreach ($_SESSION['stock_alert'] as $alert): ?>
        <div class="toast align-items-center text-bg-<?php echo htmlspecialchars($alert['type']); ?> border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body"><?php echo htmlspecialchars($alert['message']); ?></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php unset($_SESSION['stock_alert']); endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var toastEls = document.querySelectorAll('.toast');
        toastEls.forEach(function(toastEl) {
            var toast = new bootstrap.Toast(toastEl, { delay: 5000 });
            toast.show();
        });
    });
    </script>
</body>
</html>
