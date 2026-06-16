<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

header('Content-Type: application/json');

if (!isset($_SESSION['active_study_id'])) {
    echo json_encode(['success' => false, 'message' => 'No active study']);
    exit;
}

$study_id = $_SESSION['active_study_id'];
$pdo = getDB();
$action = $_POST['action'] ?? '';

try {
    if ($action === 'save_group_full') {
        $id = $_POST['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $layout = $_POST['layout'] ?? 'vertical';
        $choices = json_decode($_POST['choices'] ?? '[]', true);

        if (!$name) throw new Exception("Group name is required");

        $pdo->beginTransaction();

        try {
            if ($id) {
                // UPDATE
                // Verify ownership
                $stmt = $pdo->prepare("SELECT id FROM option_groups WHERE id = ? AND study_id = ?");
                $stmt->execute([$id, $study_id]);
                if (!$stmt->fetch()) throw new Exception("Unauthorized or group not found");

                $update = $pdo->prepare("UPDATE option_groups SET name = ?, description = ?, layout = ? WHERE id = ?");
                $update->execute([$name, $description, $layout, $id]);
                $group_id = $id;
            } else {
                // CREATE
                $insert = $pdo->prepare("INSERT INTO option_groups (study_id, name, description, layout) VALUES (?, ?, ?, ?)");
                $insert->execute([$study_id, $name, $description, $layout]);
                $group_id = $pdo->lastInsertId();
            }

            // Save Choices (Replace All)
            $stmt = $pdo->prepare("DELETE FROM option_choices WHERE group_id = ?");
            $stmt->execute([$group_id]);

            $insert_choice = $pdo->prepare("INSERT INTO option_choices (group_id, label, value, order_index) VALUES (?, ?, ?, ?)");
            
            foreach ($choices as $index => $c) {
                $label = trim($c['label']);
                $value = trim($c['value']);
                // Allow 0 as value
                if ($label === '') continue;
                if ($value === '') $value = $index; // Auto-value if empty

                $insert_choice->execute([$group_id, $label, $value, $index]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'id' => $group_id]);

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    elseif ($action === 'get_group') {
        $group_id = $_POST['group_id'];
        
        // Strictly filter by study_id
        $stmt = $pdo->prepare("SELECT * FROM option_groups WHERE id = ? AND study_id = ?");
        $stmt->execute([$group_id, $study_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$group) throw new Exception("Group not found or unauthorized");
        
        // Fetch choices
        $stmt = $pdo->prepare("SELECT * FROM option_choices WHERE group_id = ? ORDER BY order_index ASC");
        $stmt->execute([$group_id]);
        $choices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'group' => $group, 'choices' => $choices]);
    }

    elseif ($action === 'delete_group') {
        $group_id = $_POST['group_id'];
        // Verify ownership
        $stmt = $pdo->prepare("DELETE FROM option_groups WHERE id = ? AND study_id = ?");
        $stmt->execute([$group_id, $study_id]);
        if ($stmt->rowCount() === 0) throw new Exception("Group not found or unauthorized");
        
        echo json_encode(['success' => true]);
    }
    
    // Keep legacy actions if needed, but 'save_group_full' covers create/update
    // Remove old create_group / save_choices blocks to avoid confusion or keep as fallback?
    // User asked to "Enhance", replacing is cleaner.

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
