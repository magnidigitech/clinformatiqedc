<tr style="border-bottom: 1px solid var(--border-color);">
    <td style="padding: 1rem; font-weight: 500;">
        <?php echo htmlspecialchars($sub['subject_code']); ?>
    </td>
    <td style="padding: 1rem; color: var(--text-light);">
        <?php echo htmlspecialchars($sub['site_name']); ?>
    </td>
    <td style="padding: 1rem;">
        <?php 
            $statusColor = '#64748b'; // default
            $bg = '#f1f5f9';
            if ($sub['status'] == 'Active') { $statusColor = '#166534'; $bg = '#dcfce7'; }
            if ($sub['status'] == 'Screening') { $statusColor = '#854d0e'; $bg = '#fef9c3'; }
        ?>
        <span style="color: <?php echo $statusColor; ?>; background: <?php echo $bg; ?>; padding: 0.1rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 500;">
            <?php echo htmlspecialchars($sub['status']); ?>
        </span>
    </td>
    <td style="padding: 1rem;">
        <?php $prog = $sub['progress'] ?? 0; ?>
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <div style="width: 80px; height: 6px; background: #f1f5f9; border-radius: 99px; overflow: hidden;">
                <div style="width: <?php echo $prog; ?>%; height: 100%; background: var(--accent-color);"></div>
            </div>
            <span style="font-size: 0.75rem; color: var(--text-light);"><?php echo $prog; ?>%</span>
        </div>
    </td>
    <td style="padding: 1rem; color: var(--text-light);">
        <?php echo formatDate($sub['created_at']); ?>
    </td>
    <td style="padding: 1rem; text-align: right;">
        <a href="subject_data_entry.php?subject_id=<?php echo $sub['id']; ?>" class="btn btn-outline" style="padding: 0.25rem 0.6rem; font-size: 0.75rem;">View</a>
    </td>
</tr>
