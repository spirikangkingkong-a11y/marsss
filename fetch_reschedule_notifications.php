<?php
session_start();
include "db_connect.php"; // or config file

$doctor_id = $_SESSION['user_id'] ?? null;

if (!$doctor_id) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT id, caregiver_name, patient_name, message, is_read, created_at
        FROM reschedule_notifications
        WHERE doctor_id = ?
        ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

echo json_encode($notifications);
?>
