<?php
session_start();
$host = "localhost";
$user = "root";
$pass = "";
$db = "mars";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

// who is logged in (doctor or caregiver)
$user_id = $_SESSION['user_id'] ?? 0;
$role    = $_SESSION['role'] ?? '';

/**
 * 🔹 Handle POST request → mark notification as read
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notif_id = intval($_POST['id'] ?? 0);

    if ($notif_id > 0) {
       if ($role === 'Caregiver') {
    $stmt = $conn->prepare("UPDATE notifications 
                            SET is_read=1, read_at=NOW() 
                            WHERE id=? AND caregiver_id=?");
    $stmt->bind_param("ii", $notif_id, $user_id);
} elseif ($role === 'Patient') {
    $stmt = $conn->prepare("UPDATE notifications 
                            SET is_read=1, read_at=NOW() 
                            WHERE id=? AND patient_id=?");
    $stmt->bind_param("ii", $notif_id, $user_id);
} else {
    $stmt = $conn->prepare("UPDATE notifications 
                            SET is_read=1, read_at=NOW() 
                            WHERE id=?");
    $stmt->bind_param("i", $notif_id);
}


        $stmt->execute();
        $stmt->close();

        echo json_encode(["status" => "success"]);
        exit;
    }

    echo json_encode(["status" => "error"]);
    exit;
}

// 🔹 Handle GET request → fetch notifications
if ($role === 'Caregiver') {
    $stmt = $conn->prepare("
        SELECT id, message, created_at, is_read 
        FROM notifications 
        WHERE caregiver_id = ? 
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->bind_param("i", $user_id);
} else if ($role === 'Admin' || $role === 'Doctor') {
    $stmt = $conn->prepare("
        SELECT id, message, created_at, is_read 
        FROM notifications 
        ORDER BY created_at DESC LIMIT 10
    ");
} elseif ($role === 'Patient') {
    $stmt = $conn->prepare("
        SELECT id, message, created_at, is_read 
        FROM notifications 
        WHERE patient_id = ? 
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->bind_param("i", $user_id);
} else {
    echo json_encode([]);
    exit;
}

// Execute
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();

header('Content-Type: application/json');
echo json_encode($notifications);

