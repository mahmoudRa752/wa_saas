<?php if (isset($_SESSION['company_id'])): ?>
    </div></div></div><?php else: ?>
    </div></div><?php endif; ?>

<footer class="app-footer text-center py-3 text-muted border-top bg-white mt-auto" style="font-size: 0.875rem;">
    &copy; <?php echo date("Y"); ?> WA Training Manager &mdash; M.R Platform
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const ctx = document.getElementById('messageChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Sent'],
                datasets: [{
                    label: 'Messages',
                    data: [<?php echo isset($totalMessages) ? intval($totalMessages) : 0; ?>],
                    backgroundColor: ['#6366f1'],
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
</script>

</body>
</html>