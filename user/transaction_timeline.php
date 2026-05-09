<?php
// user/transaction_timeline.php - Transaction Timeline Component

function displayTransactionTimeline($conn, $transaction_id) {
    $timeline = $conn->query("
        SELECT * FROM transaction_timeline 
        WHERE transaction_id = $transaction_id 
        ORDER BY created_at ASC
    ");
    
    if ($timeline->num_rows == 0) {
        return '<p class="text-muted">No timeline events yet.</p>';
    }
    
    $html = '<div class="timeline-container">';
    $step = 1;
    while ($event = $timeline->fetch_assoc()) {
        $status_class = '';
        if (strpos($event['status'], 'completed') !== false || strpos($event['status'], 'confirmed') !== false) {
            $status_class = 'completed';
        } elseif (strpos($event['status'], 'pending') !== false || strpos($event['status'], 'waiting') !== false) {
            $status_class = 'pending';
        } else {
            $status_class = 'active';
        }
        
        $icon = getStatusIcon($event['status']);
        
        $html .= '
        <div class="timeline-item">
            <div class="timeline-marker ' . $status_class . '">
                <i class="fas ' . $icon . '"></i>
            </div>
            <div class="timeline-content">
                <div class="timeline-title">' . ucwords(str_replace('_', ' ', $event['action'])) . '</div>
                <div class="timeline-description">' . htmlspecialchars($event['description']) . '</div>
                <div class="timeline-date">' . date('M d, Y H:i', strtotime($event['created_at'])) . '</div>
            </div>
        </div>';
        $step++;
    }
    $html .= '</div>';
    
    return $html;
}

function getStatusIcon($status) {
    $icons = [
        'created' => 'fa-plus-circle',
        'payment' => 'fa-credit-card',
        'escrow' => 'fa-shield-alt',
        'delivered' => 'fa-truck',
        'confirmed' => 'fa-check-circle',
        'completed' => 'fa-check-double',
        'disputed' => 'fa-gavel',
        'cancelled' => 'fa-times-circle',
        'approved' => 'fa-thumbs-up',
        'rejected' => 'fa-thumbs-down',
        'hired' => 'fa-user-check',
        'submitted' => 'fa-paper-plane'
    ];
    
    foreach ($icons as $key => $icon) {
        if (strpos($status, $key) !== false) {
            return $icon;
        }
    }
    return 'fa-circle';
}
?>