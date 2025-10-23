<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// === Database Connection ===
$host = "localhost";
$user = "root";
$pass = "";
$db   = "mars";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$caregiver_id = $_SESSION['user_id']?? 0;

// Fetch user info
$sql  = "SELECT * FROM users WHERE id = ? AND role = 'caregiver'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $caregiver_id);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    // Logged in but not a caregiver
    header("Location: access_denied.php"); // optional page for unauthorized users
    exit;
}


$caregiver_fullname = trim(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? ''));




if (!empty($_SESSION['collab_message'])): 
    echo "<script>alert('" . addslashes($_SESSION['collab_message']) . "');</script>";
    unset($_SESSION['collab_message']);
endif;


// === Initialize Messages ===
$successMsg = "";
$errorMsg   = "";

// === Count existing patients ===
$stmt = $conn->prepare("SELECT COUNT(*) FROM caregiver_patients WHERE caregiver_id = ?");
$stmt->bind_param("i", $caregiver_id);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

            // PATIENT QUERY FOR ASSIGNING
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_patient'])) {
    $patient_id = intval($_POST['patient_id']);

    // ✅ Check if patient is already assigned to ANY caregiver
    $check = $conn->prepare("SELECT caregiver_id FROM caregiver_patients WHERE patient_id = ?");
    $check->bind_param("i", $patient_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->bind_result($existing_caregiver);
        $check->fetch();
        $check->close();

        if ($existing_caregiver == $caregiver_id) {
            echo "<script>alert('❌ This patient is already assigned to you.');</script>";
        } else {
            echo "<script>alert('⚠️ This patient is already assigned to another caregiver.');</script>";
        }
    } else {
        $check->close();

        // ✅ Get caregiver name from logged-in user
        $caregiver_name = trim(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? ''));

        // ✅ Get patient name from users table
        $p_stmt = $conn->prepare("SELECT firstname, surname FROM users WHERE id = ?");
        $p_stmt->bind_param("i", $patient_id);
        $p_stmt->execute();
        $p_stmt->bind_result($p_first, $p_last);
        $patient_name = '';
        if ($p_stmt->fetch()) {
            $patient_name = trim($p_first . ' ' . $p_last);
        }
        $p_stmt->close();

       // ✅ Check how many patients the caregiver already has
        $count_query = $conn->prepare("SELECT COUNT(*) FROM caregiver_patients WHERE caregiver_id = ?");
        $count_query->bind_param("i", $caregiver_id);
        $count_query->execute();
        $count_query->bind_result($current_count);
        $count_query->fetch();
        $count_query->close();

        if ($current_count >= 3) {
            echo "<script>alert('⚠️ You already have 3 assigned patients. You cannot assign more.');</script>";
        } else {
            // ✅ Fetch patient name
            $p_stmt = $conn->prepare("SELECT firstname, surname FROM users WHERE id = ?");
            $p_stmt->bind_param("i", $patient_id);
            $p_stmt->execute();
            $p_stmt->bind_result($pf, $pl);
            $p_stmt->fetch();
            $p_stmt->close();

            $patient_name   = trim($pf . ' ' . $pl);
            $caregiver_name = trim(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? ''));

            // ✅ Proceed to assign if not already taken and within limit
            $assign_query = "INSERT INTO caregiver_patients (caregiver_id, caregiver_name, patient_id, patient_name, assigned_date)
                            VALUES (?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($assign_query);
            $stmt->bind_param("isis", $caregiver_id, $caregiver_name, $patient_id, $patient_name);

            if ($stmt->execute()) {
                echo "<script>alert('✅ Patient assigned successfully!'); window.location.href='Caregiver.php';</script>";
            } else {
                echo "<script>alert('❌ Error assigning patient: " . addslashes($stmt->error) . "');</script>";
            }
            $stmt->close();
        }
     }
    }




// === Fetch Doctors ===
$doctors = $conn->query("SELECT id, firstname, surname, specialization FROM users WHERE role = 'doctor'");

// === Fetch All Doctors for Collaboration Dropdown ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_all_doctors'])) {
    $spec = $_POST['specialization'] ?? '';
    if (!empty($spec)) {
        $stmt = $conn->prepare("SELECT id, firstname, surname FROM users WHERE role='doctor' AND specialization = ?");
        $stmt->bind_param("s", $spec);
        $stmt->execute();
        $res = $stmt->get_result();

        echo '<option value="">-- Select Doctor --</option>';
        while ($row = $res->fetch_assoc()) {
            echo '<option value="'.$row['id'].'">Dr. '.htmlspecialchars($row['firstname'].' '.$row['surname']).'</option>';
        }
        $stmt->close();
    }
    exit;
}


// === Request Appointment ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['request_appointment'])) {
    $patient_id = intval($_POST['patient_id']);
    $doctor_id  = intval($_POST['doctor_id']);
    $date       = $_POST['appointment_date'];

    // ✅ Prevent past dates
   $today = date("Y-m-d H:i:s");
    if ($date < $today) {
        echo "<script>
            alert('❌ You cannot request an appointment for a past date or time.');
            window.location.href = 'Caregiver.php';
        </script>";
        exit;
    }


    // Patient name
    $patient_name = '';
    $p_stmt = $conn->prepare("SELECT firstname, surname FROM users WHERE id=?");
    $p_stmt->bind_param("i", $patient_id);
    $p_stmt->execute();
    $p_stmt->bind_result($p_first, $p_last);
    if ($p_stmt->fetch()) {
        $patient_name = trim($p_first . ' ' . $p_last);
    }
    $p_stmt->close();

    // Doctor name
    $doctor_name = '';
    $d_stmt = $conn->prepare("SELECT firstname, surname FROM users WHERE id=?");
    $d_stmt->bind_param("i", $doctor_id);
    $d_stmt->execute();
    $d_stmt->bind_result($d_first, $d_last);
    if ($d_stmt->fetch()) {
        $doctor_name = trim($d_first . ' ' . $d_last);
    }
    $d_stmt->close();
    
      // Caregiver name
    $caregiver_name = trim(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? ''));
    
    // Insert Appointment
    $stmt = $conn->prepare(
        "INSERT INTO appointments
        (patient_id, doctor_id, caregiver_id, appointment_date, patient_name, doctor_name, caregiver_name, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
    );
    $stmt->bind_param("iiissss", $patient_id, $doctor_id, $caregiver_id, $date, $patient_name, $doctor_name, $caregiver_fullname);

        if ($stmt->execute()) {
        $stmt->close();
        // Echo JavaScript alert
        echo "<script>
            alert('Appointment requested successfully!');
            window.location.href = 'Caregiver.php';
        </script>";
        exit;
    } else {
        $errorMsg = $stmt->error;
        $stmt->close();
        echo "<script>
            alert('Error: {$errorMsg}');
            window.location.href = 'Caregiver.php';
        </script>";
        exit;
    }

}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_contract'])) {
    $patient_id = intval($_POST['patient_id']);

    $stmt = $conn->prepare("DELETE FROM caregiver_patients WHERE caregiver_id = ? AND patient_id = ?");
    $stmt->bind_param("ii", $caregiver_id, $patient_id);

    if ($stmt->execute()) {
        echo "<script>alert('✅ Patient contract ended successfully.'); window.location.href='Caregiver.php';</script>";
        exit;
    } else {
        echo "<script>alert('❌ Error ending contract: " . addslashes($stmt->error) . "');</script>";
    }

    $stmt->close();
}



// === Request Collaboration ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['request_collaboration'])) {
    $doctor_id = intval($_POST['doctor_id']);
    

    // Doctor name
    $doc_sql = "SELECT firstname, surname FROM users WHERE id = ?";
    $stmt    = $conn->prepare($doc_sql);
    $stmt->bind_param("i", $doctor_id);
    $stmt->execute();
    $doc_result = $stmt->get_result();
    $doc        = $doc_result->fetch_assoc();
    $stmt->close();
    $doctor_name = $doc['firstname'] . " " . $doc['surname'];

    // 🔍 Check if there is ANY existing request (pending OR approved) with this doctor
    $chk = $conn->prepare("
        SELECT status FROM collaboration_requests
        WHERE caregiver_id = ? AND doctor_id = ?
          AND (status = 'pending' OR status = 'approved')
          AND terminated_at IS NULL
        LIMIT 1
    ");
    $chk->bind_param("ii", $caregiver_id, $doctor_id);
    $chk->execute();
    $chk->store_result();

    if ($chk->num_rows > 0) {
        $chk->bind_result($existing_status);
        $chk->fetch();
        $chk->close();

        if ($existing_status === "approved") {
            $_SESSION['collab_message'] = "❌ You already have an active collaboration with Dr. $doctor_name";
header("Location: Caregiver.php?tab=collaborations");
exit;

        } else {
            echo "<script>
                    alert('⚠️ You already sent a request to Dr. " . addslashes($doctor_name) . " (STILL PENDING).');
                </script>";
        }
    } else {
        $chk->close();

        // ✅ Insert only if no duplicate
        $stmt = $conn->prepare("
            INSERT INTO collaboration_requests
            (caregiver_id, doctor_id, caregiver_name, doctor_name, status, requested_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("iiss", $caregiver_id, $doctor_id, $caregiver_fullname, $doctor_name);

       if ($stmt->execute()) {
    $_SESSION['collab_message'] = "✅ Collaboration request sent!";
        header("Location: Caregiver.php?tab=collaborations");
        exit;

} else {
    echo "<script>alert('❌ Error: " . addslashes($stmt->error) . "');</script>";
}

        $stmt->close();
    }
    
}




// === Approved Collaborations ===
$approved_doctors_sql = "
    SELECT u.id, u.firstname, u.surname
    FROM collaboration_requests c
    JOIN users u ON u.id = c.doctor_id
    WHERE c.caregiver_id = ? AND c.status = 'approved'
";
$approved_stmt = $conn->prepare($approved_doctors_sql);
$approved_stmt->bind_param("i", $caregiver_id);
$approved_stmt->execute();
$approved_doctors = $approved_stmt->get_result();
$approved_stmt->close();

// === All Collaborations ===
$my_collaborations_sql = "
    SELECT  u.id, u.firstname, u.surname, specialization, c.requested_at
    FROM collaboration_requests c
    JOIN users u ON u.id = c.doctor_id
    WHERE c.caregiver_id = ? AND c.status = 'approved'
";
$my_collab_stmt = $conn->prepare($my_collaborations_sql);
$my_collab_stmt->bind_param("i", $caregiver_id);
$my_collab_stmt->execute();
$my_collaborations = $my_collab_stmt->get_result();
$my_collab_stmt->close();


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_approved_doctors'])) {
    $spec = $_POST['specialization'] ?? '';
    if (!empty($spec)) {
        $stmt = $conn->prepare("
            SELECT u.id, u.firstname, u.surname 
            FROM collaboration_requests c
            JOIN users u ON u.id = c.doctor_id
            WHERE c.caregiver_id = ? 
              AND c.status = 'approved' 
              AND u.specialization = ?
        ");
        $stmt->bind_param("is", $caregiver_id, $spec);
        $stmt->execute();
        $res = $stmt->get_result();

        echo '<option value="">-- Select Approved Doctor --</option>';
        while ($row = $res->fetch_assoc()) {
            echo '<option value="'.$row['id'].'">Dr. '.htmlspecialchars($row['firstname'].' '.$row['surname']).'</option>';
        }
        $stmt->close();
    }
    exit;
}


            // === Appointments List ===
            $today = date("Y-m-d H:i:s");

           $appointments_sql = "
            SELECT a.appointment_date, a.status,
                p.firstname AS patient_first, p.surname AS patient_last,
                d.firstname AS doctor_first, d.surname AS doctor_last
            FROM appointments a
            JOIN users p ON a.patient_id = p.id
            JOIN users d ON a.doctor_id = d.id
            WHERE a.caregiver_id = ?
            AND a.status IN ('pending', 'rejected')
            ORDER BY a.appointment_date ASC
            LIMIT 5
            ";
            $appointments_stmt = $conn->prepare($appointments_sql);
            $appointments_stmt->bind_param("i", $caregiver_id); // only one param

            $appointments_stmt->execute();
            $result = $appointments_stmt->get_result();

            $appointments = [];
            while ($row = $result->fetch_assoc()) {
                $appointments[] = $row;
            }
            $appointments_count = count($appointments);
            $appointments_stmt->close();

            // === Only Confirmed Appointments (for Upcoming Appointments) ===
            $upcoming_sql = "
                SELECT a.appointment_date, a.status,
                    p.firstname AS patient_first, p.surname AS patient_last,
                    d.firstname AS doctor_first, d.surname AS doctor_last
                FROM appointments a
                JOIN users p ON a.patient_id = p.id
                JOIN users d ON a.doctor_id = d.id
                WHERE a.caregiver_id = ? 
                AND a.status = 'confirmed'
                AND a.appointment_date >= ?
                ORDER BY a.appointment_date ASC
                LIMIT 10
            ";
            $upcoming_stmt = $conn->prepare($upcoming_sql);
            $upcoming_stmt->bind_param("is", $caregiver_id, $today);
            $upcoming_stmt->execute();
            $upcoming = $upcoming_stmt->get_result();
            $upcoming_stmt->close();



            // === Request Reschedule With Notifications ===
        if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_reschedule'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $new_date = $_POST['new_date'];

 // ✅ Prevent past dates
    $today = date("Y-m-d H:i:s");
    if ($new_date < $today) {
        echo "<script>
            alert('❌ You cannot request a reschedule to a past date or time.');
            window.location.href='Caregiver.php?tab=appointments';
        </script>";
        exit;
    }
          
    // ✅ Update appointment with new reschedule info
    $stmt = $conn->prepare("
        UPDATE appointments 
        SET reschedule_request_date = NOW(),
            reschedule_new_date = ?, 
            reschedule_status = 'pending'
        WHERE appointment_id = ?
    ");
    $stmt->bind_param("si", $new_date, $appointment_id);

    if ($stmt->execute()) {

        // ✅ Fetch doctor & patient info for notification
        $info = $conn->prepare("
            SELECT a.doctor_id, a.patient_id, a.patient_name, u.firstname, u.surname 
            FROM appointments a
            JOIN users u ON a.doctor_id = u.id
            WHERE a.appointment_id = ?
        ");
        $info->bind_param("i", $appointment_id);
        $info->execute();
        $result = $info->get_result()->fetch_assoc();
        $info->close();

        $doctor_id   = $result['doctor_id'];
        $doctor_name = $result['firstname'] . " " . $result['surname'];
        $patient_id  = $result['patient_id'];
        $patient_name = $result['patient_name'];


        
                    // ✅ Notification message
                $message = "📅 Reschedule Request :  Requested new date/time for patient $patient_name: " . date("F d, Y h:i A", strtotime($new_date));

                // ✅ Save Notification (correct types and order)
              $notif = $conn->prepare("
                INSERT INTO reschedule_notifications 
                (doctor_id, doctor_name, caregiver_id, caregiver_name, patient_id, patient_name, message, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
            ");

                $notif->bind_param("isisiss", 
                    $doctor_id, 
                    $doctor_name, 
                    $caregiver_id, 
                    $caregiver_fullname, 
                    $patient_id, 
                    $patient_name, 
                    $message
                );

                $notif->execute();
                $notif->close();



        echo "<script>alert('✅ Reschedule request sent to doctor!'); window.location.href='Caregiver.php?tab=appointments';</script>";
    } else {
        echo "<script>alert('❌ Error sending request!');</script>";
    }
    $stmt->close();
}



            // ✅ ADD REMINDER BLOCK HERE
           // === Appointment Reminders (1 hour before) ===
                $reminder_sql = "
                            SELECT appointment_id, patient_id, patient_name, doctor_id, doctor_name, appointment_date
                            FROM appointments
                            WHERE caregiver_id = ?
                            AND status = 'confirmed'
                            AND appointment_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR)
                        ";
                        $reminder_stmt = $conn->prepare($reminder_sql);
                        $reminder_stmt->bind_param("i", $caregiver_id);
                        $reminder_stmt->execute();
                        $res = $reminder_stmt->get_result();

                        while ($row = $res->fetch_assoc()) {
                            $msg = "⏰ Reminder: Appointment for {$row['patient_name']} with Dr. {$row['doctor_name']} at " 
                                . date("h:i A", strtotime($row['appointment_date']));

                            $check = $conn->prepare("SELECT 1 FROM notifications WHERE caregiver_id=? AND message=?");
                            $check->bind_param("is", $caregiver_id, $msg);
                            $check->execute();
                            $check->store_result();

                            if ($check->num_rows == 0) {
                                $ins = $conn->prepare("
                                    INSERT INTO notifications 
                                    (doctor_id, doctor_name, caregiver_id, caregiver_name, patient_id, patient_name, message, is_read, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
                                ");
                                $ins->bind_param(
                                    "isisiss",
                                    $row['doctor_id'],     // ✅ now included in SELECT
                                    $row['doctor_name'],   // ✅ now included in SELECT
                                    $caregiver_id,
                                    $caregiver_fullname,
                                    $row['patient_id'],
                                    $row['patient_name'],
                                    $msg
                                );
                                $ins->execute();
                                $ins->close();
                            }
                            $check->close();
                        }
                        $reminder_stmt->close();




// === Terminate Collaboration ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['terminate_collab'])) {
    $doctor_id = intval($_POST['doctor_id']);

    $stmt = $conn->prepare("
        UPDATE collaboration_requests 
        SET status = 'terminated', terminated_at = NOW() 
        WHERE caregiver_id = ? AND doctor_id = ? AND status = 'approved'
    ");
    $stmt->bind_param("ii", $caregiver_id, $doctor_id);
    if ($stmt->execute()) {
        echo "<script>alert('Collaboration terminated successfully.'); window.location.href='Caregiver.php';</script>";
        exit;
    } else {
        echo "<script>alert('Error terminating collaboration: " . addslashes($stmt->error) . "'); window.location.href='Caregiver.php';</script>";
        exit;
    }
    $stmt->close();
}

// === Notifications API for AJAX ===
if (isset($_GET['action']) && $_GET['action'] === "fetch_notifications") {
    header("Content-Type: application/json");

    $stmt = $conn->prepare("
        SELECT n.id, n.message, n.is_read, n.created_at,
           u.firstname AS doctor_first, u.surname AS doctor_last
            FROM notifications n
            JOIN users u ON n.doctor_id = u.id
            WHERE n.caregiver_id = ?
            ORDER BY n.created_at DESC
            LIMIT 10
    ");
    $stmt->bind_param("i", $caregiver_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifs = [];
    while ($row = $result->fetch_assoc()) {
        $notifs[] = $row;
    }
    $stmt->close();

    echo json_encode($notifs);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === "mark_read") {
    header("Content-Type: application/json");

    $id = intval($_POST['id']);
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND caregiver_id = ?");
    $stmt->bind_param("ii", $id, $caregiver_id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "error" => $stmt->error]);
    }
    $stmt->close();
    exit;
}

// === Fetch My Assigned Patients ===
$patients_list = [];

$sql = "
    SELECT u.id, u.firstname, u.surname, u.birthdate, u.gender
    FROM caregiver_patients cp
    JOIN users u ON u.id = cp.patient_id
    WHERE cp.caregiver_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $caregiver_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $patients_list[] = $row;
}
$stmt->close();

// -----  UPDATING PROFILE INFORMATION ------//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $firstname    = $_POST['firstname'];
    $middle       = $_POST['middle'];
    $surname      = $_POST['surname'];
    $birthdate    = $_POST['birthdate'];
    $gender       = $_POST['gender'];
    $contact      = $_POST['contact'];
    $email        = $_POST['email'];
    $province     = $_POST['province'];
    $city_address = $_POST['city_address'];
    $brgy_address = $_POST['brgy_address'];
    $home_address = $_POST['home_address'];

    $update = $conn->prepare("UPDATE users SET 
        firstname = ?, middle = ?, surname = ?, birthdate = ?, gender = ?, contact = ?, 
        email = ?, province = ?, city_address = ?, brgy_address = ?, home_address = ?
        WHERE id = ?");
    $update->bind_param("sssssssssssi", 
        $firstname, $middle, $surname, $birthdate, $gender, $contact, 
        $email, $province, $city_address, $brgy_address, $home_address, $caregiver_id
    );

    if ($update->execute()) {
        echo "<script> alert('Profile updated successfully!'); window.location.href = 'Caregiver.php'; // or the page you want to go back to</script>";
    exit;
    } else {
         echo "<script>alert('Error updating profile!'); window.location.href = 'Caregiver.php';</script>";
    exit;
    }
    $update->close();
}


// === Delete Selected Notifications ===
// ✅ Only runs when delete_notifications is triggered via JavaScript (JSON)
if ($_SERVER["REQUEST_METHOD"] === "POST" && 
    empty($_POST) && 
    ($input = json_decode(file_get_contents("php://input"), true)) && 
    isset($input['action']) && $input['action'] === "delete_notifications") {

    header("Content-Type: application/json");

    $ids = $input['ids'] ?? [];
    if (empty($ids)) {
        echo json_encode(["status" => "error", "message" => "No IDs provided."]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $conn->prepare("DELETE FROM notifications WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);

    echo $stmt->execute()
        ? json_encode(["status" => "success"])
        : json_encode(["status" => "error", "message" => $stmt->error]);

    $stmt->close();
    exit;
}

?>




<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
    <title>Caregiver Dashboard</title>
</head>
<body id="caregiver-page">

     <!-- Responsive Header -->
  <header class="top-header">
    <div class="header-left">
      <h2 id="header-title">🩺 MARS Caregiver Dashboard</h2>
      <span class="welcome-text" id="header-subtitle">
        Welcome Admin, <?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? '')); ?>!
      </span>
    </div>
  </header>

    <div id="main-page">
        <div class="container">
            <div class="overlay"></div>
            <div class="top-bar">
            <button class="hamburger" id="menu-btn">&#9776;</button>
             <div id="notif-bell">
                         🔔
                    <span id="notif-count">0</span>
                </div>
             </div>

            <!-- Sidebar -->
           <aside class="sidebar">
    <div class="profile-circle"></div>
    <ul class="nav">
        <li data-target="dashboard" <?php if (!isset($_GET['tab']) || $_GET['tab'] === 'dashboard') echo 'class="active"'; ?>>Dashboard</li>
        <li data-target="patients" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'patients') echo 'class="active"'; ?>>My Patients</li>
        <li data-target="collaborations" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'collaborations') echo 'class="active"'; ?>>Collaborations</li>
        <li data-target="appointments" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'appointments') echo 'class="active"'; ?>>Appointments</li>
        <li data-target="notifications" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'notifications') echo 'class="active"'; ?>>Notifications</li>
        <li data-target="profile" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'profile') echo 'class="active"'; ?>>Profile Information</li>
        <li id="logout-btn">Log out</li>
    </ul>
</aside>


            <!-- Main Content -->
            <main class="main-content">
                <!-- show messages -->
                <?php if (!empty($successMsg)): ?>
                    <div class="alert success"><?php echo htmlspecialchars($successMsg); ?></div>
                <?php endif; ?>
                <?php if (!empty($errorMsg)): ?>
                    <div class="alert error"><?php echo htmlspecialchars($errorMsg); ?></div>
                <?php endif; ?>

                <!-- Dashboard -->
                <div id="dashboard" class="content-section active">
                    <div class="cards">
                        <div class="card">
                            <div class="card-icon">&#128100;</div>
                            <div class="card-info">
                                <h3>Total Patients</h3>
                                <p><?php echo count($patients_list); ?> / 3</p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-icon">&#128197;</div>
                            <div class="card-info">
                                <h3>Upcoming Appointments</h3>
                                <p><?php echo $appointments_count; ?> Scheduled</p>
                            </div>
                        </div>
                    </div>
                    <br></br>
                    <h1>Upcoming Appointments</h1>
                        <table>
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Doctor</th>
                                    <th>Date & Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($upcoming->num_rows > 0): ?>
                                    <?php while ($a = $upcoming->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($a['patient_first']." ".$a['patient_last']); ?></td>
                                            <td>Dr. <?= htmlspecialchars($a['doctor_first']." ".$a['doctor_last']); ?></td>
                                            <td><?= date("M d, Y h:i A", strtotime($a['appointment_date'])); ?></td>
                                            <td><?= ucfirst($a['status']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4">No upcoming appointments</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                </div>

       
               <!-- All Patients -->
                <div id="patients" class="content-section">
                    <h1>All Patients</h1>
                    <table>
                        <thead>
                            <tr>
                                <th>Patient Name</th>
                                <th>Birthdate</th>
                                <th>Gender</th>
                                <th>Contact</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Fetch all patients from the users table
                            $query = "SELECT * FROM users WHERE role = 'Patient'";
                            $patients = $conn->query($query);

                            if ($patients && $patients->num_rows > 0):
                                while ($row = $patients->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['surname']); ?></td>
                                    <td><?php echo htmlspecialchars($row['birthdate']); ?></td>
                                    <td><?php echo htmlspecialchars($row['gender']); ?></td>
                                    <td><?php echo htmlspecialchars($row['contact']); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" 
                                            onsubmit="return confirm('Are you sure you want to assign this patient to yourself?');">
                                            <input type="hidden" name="patient_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="assign_patient">Assign to Me</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php
                                endwhile;
                            else:
                                echo "<tr><td colspan='5'>No patients found.</td></tr>";
                            endif;
                            ?>
                        </tbody>
                    </table>

                    <h1>My Patients</h1>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th><th>Birthdate</th><th>Gender</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                           <?php if (!empty($patients_list)): ?>
                                <?php foreach ($patients_list as $row): ?>
                                    <tr>
                                    <td><?php echo htmlspecialchars($row['firstname']." ".$row['surname']); ?></td>
                                    <td><?php echo htmlspecialchars($row['birthdate']); ?></td>
                                    <td><?php echo htmlspecialchars($row['gender']); ?></td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to end this patient contract?');">
                                        <input type="hidden" name="patient_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="end_contract">End Contract</button>
                                        </form>
                                    </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr><td colspan="4">No patients assigned yet.</td></tr>
                                <?php endif; ?>
                        </tbody>
                    </table>
                </div>

<!-- Collaborations -->
<div id="collaborations" class="content-section">
    <h1>Request Doctor</h1>      
   <div class="white-panel">
    <form method="POST" action="">
        <div class="form-row">

            <!-- Specialization -->
            <div class="form-group">
                <label for="specialization">Doctor Specialization:</label>
               <select id="collab_specialization" name="specialization">
                    <option value="">-- Select Specialization --</option>
                    <option value="General Medicine" <?php if(isset($_POST['specialization']) && $_POST['specialization']=='General Medicine') echo 'selected'; ?>>General Medicine</option>
                    <option value="Geriatrician" <?php if(isset($_POST['specialization']) && $_POST['specialization']=='Geriatrician') echo 'selected'; ?>>Geriatrician</option>
                    <option value="Cardiologist" <?php if(isset($_POST['specialization']) && $_POST['specialization']=='Cardiologist') echo 'selected'; ?>>Cardiologist</option>
                    <option value="Neurologist" <?php if(isset($_POST['specialization']) && $_POST['specialization']=='Neurologist') echo 'selected'; ?>>Neurologist</option>
                    <option value="Endocrinologist" <?php if(isset($_POST['specialization']) && $_POST['specialization']=='Endocrinologist') echo 'selected'; ?>>Endocrinologist</option>
                    <option value="Pulmonologist" <?php if(isset($_POST['specialization']) && $_POST['specialization']=='Pulmonologist') echo 'selected'; ?>>Pulmonologist</option>
                    <option value="Orthopedic" <?php if(isset($_POST['specialization']) && $_POST['specialization']=='Orthopedic') echo 'selected'; ?>>Orthopedic</option>
                    <option value="Psychiatrist" <?php if(isset($_POST['specialization']) && $_POST['specialization']=='Psychiatrist') echo 'selected'; ?>>Psychiatrist</option>
                </select>
            </div>

                 <!-- Doctor -->
<div class="form-group">
    <label for="doctor_id">Select Doctor:</label>
    <select name="doctor_id" id="doctor_id" required>
        <option value="">-- Choose Doctor --</option>
        <?php
        $specialization = $_POST['specialization'] ?? '';
        if (!empty($specialization)) {
            $stmt = $conn->prepare("SELECT id, firstname, surname FROM users WHERE role='doctor' AND specialization = ?");
            $stmt->bind_param("s", $specialization);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                echo '<option value="'.$row['id'].'">Dr. '.htmlspecialchars($row['firstname'].' '.$row['surname']).'</option>';
            }
        }
        ?>
    </select>
</div>
                   

            <!-- Submit Button -->
            <div class="form-group submit-group">
                <label>&nbsp;</label> <!-- keep label spacing -->
                <button type="submit" name="request_collaboration" class="btn-primary">Request Doctor</button>
            </div>

        </div>
    </form>
</div>

    <!-- My Doctor's List -->
    <h1>My Doctor's list</h1>
    <table>
        <thead>
            <tr><th>Doctor</th><th>Specialization</th><th>Since</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php if ($my_collaborations && $my_collaborations->num_rows > 0): ?>
                <?php while ($row = $my_collaborations->fetch_assoc()): ?>
                    <tr>
                        <td>Dr. <?php echo htmlspecialchars($row['firstname']." ".$row['surname']); ?></td>
                        <td><?php echo htmlspecialchars($row['specialization']); ?></td>
                        <td><?php echo htmlspecialchars(date("M d, Y", strtotime($row['requested_at']))); ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to terminate this collaboration?');">
                                <input type="hidden" name="doctor_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="terminate_collab">End Collaboration</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4">No active collaborations yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

</div> <!-- content-section -->

<!-- Appointments -->
<div id="appointments" class="content-section">
    <h1>Request Appointment</h1>
    <div class="white-panel">
        <form method="POST" action="" class="appointment-form">

            <!-- Row 1: Doctor Specialization + Doctor -->
            <div class="form-row">
                <div class="form-group">
                    <label for="specialization">Doctor Specialization:</label>
                    <select id="appointment_specialization" name="specialization" required> 
                        <option value="">-- Select Specialization --</option>
                        <option value="General Medicine">General Medicine</option>
                        <option value="Geriatrician">Geriatrician</option>
                        <option value="Cardiologist">Cardiologist</option>
                        <option value="Neurologist">Neurologist</option>
                        <option value="Endocrinologist">Endocrinologist</option>
                        <option value="Pulmonologist">Pulmonologist</option>
                        <option value="Orthopedic">Orthopedic</option>
                        <option value="Psychiatrist">Psychiatrist</option>
                    </select>
                </div>

                        <div class="form-group">
                <label for="doctor_id">Doctor:</label>
                <select name="doctor_id" id="appointment_doctor_id" required>
                    <option value="">-- Select Approved Doctor --</option>
                    <?php
                    $selected_spec = $_POST['specialization'] ?? '';
                    if (!empty($selected_spec)) {
                        $stmt = $conn->prepare("
                            SELECT u.id, u.firstname, u.surname 
                            FROM collaboration_requests c
                            JOIN users u ON u.id = c.doctor_id
                            WHERE c.caregiver_id = ? 
                            AND c.status = 'approved' 
                            AND u.specialization = ?
                        ");
                        $stmt->bind_param("is", $caregiver_id, $selected_spec);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($row = $res->fetch_assoc()) {
                            echo '<option value="'.$row['id'].'">Dr. '.htmlspecialchars($row['firstname'].' '.$row['surname']).'</option>';
                        }
                        $stmt->close();
                    }
                    ?>
                </select>
            </div>

            </div>

            <!-- Row 2: Patient + Date/Time + Button -->
            <div class="form-row">
                <div class="form-group">
                    <label for="patient_id">Patient:</label>
                    <select name="patient_id" id="patient_id" required>
                        <option value="">-- Select Patient --</option>
                        <?php foreach ($patients_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>">
                                <?php echo htmlspecialchars($p['firstname']." ".$p['surname']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="appointment_date">Date & Time:</label>
                    <input type="datetime-local" name="appointment_date" id="appointment_date" min="<?= date('Y-m-d\TH:i'); ?>" required>
                </div>

                <div class="submit-group">
                    <button type="submit" name="request_appointment" class="btn-primary">Request Appointment</button>
                </div>
            </div>

        </form>
    </div>


                      
                   <?php
                $reschedule_sql = "
                    SELECT a.appointment_id, a.patient_name, a.doctor_name, a.appointment_date, a.status, a.reschedule_status
                    FROM appointments a
                    WHERE a.caregiver_id = ?
                    AND a.appointment_date >= NOW()
                    ORDER BY a.appointment_date ASC
                ";

                $stmt = $conn->prepare($reschedule_sql);
                $stmt->bind_param("i", $caregiver_id);
                $stmt->execute();
                $reschedules = $stmt->get_result();
                ?>

<h1>Appointments List</h1>
<table>
    <tr>
        <th>Patient</th>
        <th>Doctor</th>
        <th>Date</th>
        <th>Status</th>
        <th>Reschedule Status</th>
    </tr>

    <?php if ($reschedules->num_rows > 0): ?>
        <?php while ($row = $reschedules->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['patient_name']); ?></td>
                <td><?= htmlspecialchars($row['doctor_name']); ?></td>
                <td><?= date("M d, Y h:i A", strtotime($row['appointment_date'])); ?></td>
                <td><?= ucfirst($row['status']); ?></td>
                <td>
                        <?php if ($row['reschedule_status'] == 'pending'): ?>
                            <small>⏳ Waiting for doctor's approval...</small>

                        <?php elseif ($row['status'] == 'pending'): ?>
                            <button disabled style="background: gray; cursor: not-allowed;">Reschedule</button>

                        <?php elseif ($row['status'] == 'confirmed'): ?>
                            <form method="POST" action="">
                                <input type="hidden" name="appointment_id" value="<?= $row['appointment_id']; ?>">
                                <input type="datetime-local" name="new_date" required>
                                <button type="submit" name="send_reschedule">Request Reschedule</button>
                            </form>

                        <?php endif; ?>
                    </td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr><td colspan="5">No appointments scheduled yet.</td></tr>
    <?php endif; ?>
</table>


                </div>

                <!-- Notifications -->
                <div id="notifications" class="content-section">
                    <h1>System Notifications</h1>
                    <div class="notif-actions">
                        <label><input type="checkbox" id="select-all"> Select All</label>
                        <button id="delete-selected">🗑 Delete Selected</button>
                    </div>
                    <div class="notif-cards" id="notif-list"></div>                
                </div>


                <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
                    <script> alert("✅ Profile updated successfully!"); </script>
                <?php endif; ?>

             <!-- Profile -->
                <div id="profile" class="content-section">
  <div class="profile-box">
    <form method="POST" id="profileForm">

      <div class="form-group">
        <label>First Name:</label>
        <input type="text" name="firstname" value="<?php echo $user['firstname'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Middle Name:</label>
        <input type="text" name="middle" value="<?php echo $user['middle'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Last Name:</label>
        <input type="text" name="surname" value="<?php echo $user['surname'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Birthdate:</label>
        <input type="date" name="birthdate" value="<?php echo $user['birthdate'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Gender:</label>
        <input type="text" name="gender" value="<?php echo $user['gender'] ?? ''; ?>" readonly>
      </div>

        <div class="form-group">
        <label>Email:</label>
        <input type="email" name="email" 
              value="<?php echo $user['email'] ?? ''; ?>" 
              readonly 
              required 
              pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$" 
              title="Please enter a valid email address">
      </div>

      <div class="form-group">
        <label>Contact:</label>
        <input type="text" name="contact" 
              value="<?php echo $user['contact'] ?? ''; ?>" 
              readonly 
              required 
              pattern="\d{10,11}" 
              title="Contact must be 10 or 11 digits only">
      </div>

      <div class="form-group">
        <label>Province:</label>
        <input type="text" name="province" value="<?php echo $user['province'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>City Address:</label>
        <input type="text" name="city_address" value="<?php echo $user['city_address'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Barangay:</label>
        <input type="text" name="brgy_address" value="<?php echo $user['brgy_address'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Home Address:</label>
        <input type="text" name="home_address" value="<?php echo $user['home_address'] ?? ''; ?>" readonly>
      </div>

      <!-- Make buttons full width (span 2 columns) -->
      <div id="profile-buttons">
        <button type="button" id="editBtn">Edit</button>
        <button type="submit" name="save_profile" id="saveBtn" style="display:none;">Save</button>
        <button type="button" id="cancelBtn" disabled>Cancel</button>
      </div>
    </form>
  </div>
</div>

            </main>
        </div>
    </div>

  <script>
function fetchDoctors(specialization, doctorDropdownId, isCollab = false) {
    let bodyData = (isCollab ? "fetch_all_doctors=1&" : "fetch_approved_doctors=1&") + "specialization=" + encodeURIComponent(specialization);

    fetch("Caregiver.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: bodyData
    })
    .then(response => response.text())
    .then(data => {
        document.getElementById(doctorDropdownId).innerHTML = data;
    });
}

// Collaboration dropdown fetches all doctors
document.getElementById("collab_specialization").addEventListener("change", function() {
    fetchDoctors(this.value, "doctor_id", true);
});

// Appointment dropdown fetches only approved doctors
document.getElementById("appointment_specialization").addEventListener("change", function() {
    fetchDoctors(this.value, "appointment_doctor_id", false);
});
</script>


<script>
document.addEventListener("DOMContentLoaded", () => {

  const editBtn = document.getElementById("editBtn");
  const cancelBtn = document.getElementById("cancelBtn");
  const saveBtn = document.getElementById("saveBtn");
  const profileForm = document.getElementById("profileForm");
  const contactInput = document.querySelector('input[name="contact"]');

  // Only allow numbers and max 11 digits in contact
  contactInput.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0,11);
  });

  // Edit button click
  editBtn.addEventListener("click", () => {
      profileForm.querySelectorAll("input").forEach(input => {
          input.removeAttribute("readonly");
          input.disabled = false;
      });

      saveBtn.style.display = "inline-block";
      cancelBtn.disabled = false;
      editBtn.disabled = true;
  });

  // Cancel button click
  cancelBtn.addEventListener("click", () => {
      location.reload(); // Reload page to reset form
  });

});
</script>



    <!-- === DYNAMIC HEADER TEXT UPDATE (PUT THIS FIRST) === -->
<script>
// === Dynamic Header Text Update for Caregiver ===
const headerTitle = document.getElementById("header-title");
const headerSubtitle = document.getElementById("header-subtitle");

// Titles and subtitles for each Caregiver section
const sectionTitles = {
  dashboard: {
    title: "👩‍⚕️ MARS Caregiver Dashboard",
    subtitle: "Welcome Caregiver, <?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? '')); ?>!"
  },
  patients: {
    title: "🧍‍♂️ Patient Management",
    subtitle: "View and manage your assigned patients and requests."
  },
  collaborations: {
    title: "🤝 Doctor Collaborations",
    subtitle: "Request and manage collaborations with doctors."
  },
  appointments: {
    title: "📅 Appointments",
    subtitle: "Schedule and manage patient appointments with doctors."
  },
  notifications: {
    title: "🔔 Notifications Center",
    subtitle: "View reminders, alerts, and recent activity updates."
  },
  profile: {
    title: "👤 Profile Information",
    subtitle: "Update your personal details and caregiver account."
  }
};

document.addEventListener("DOMContentLoaded", () => {
    const navItems = document.querySelectorAll(".sidebar .nav li");
    const sections = document.querySelectorAll(".content-section");

    // 🔹 Restore last active tab from localStorage
    const activeTab = localStorage.getItem("activeTab") || "dashboard";

    navItems.forEach(nav => nav.classList.remove("active"));
    sections.forEach(sec => sec.classList.remove("active"));

    const activeNav = document.querySelector(`[data-target="${activeTab}"]`);
    const activeSection = document.getElementById(activeTab);
    if (activeNav) activeNav.classList.add("active");
    if (activeSection) activeSection.classList.add("active");

    // Update header
    if (sectionTitles[activeTab]) {
        headerTitle.textContent = sectionTitles[activeTab].title;
        headerSubtitle.textContent = sectionTitles[activeTab].subtitle;
    }

    // 🔹 Click events
    navItems.forEach(item => {
        item.addEventListener("click", () => {
            const target = item.dataset.target;
            if (!target) return;

            localStorage.setItem("activeTab", target);

            navItems.forEach(nav => nav.classList.remove("active"));
            sections.forEach(section => section.classList.remove("active"));

            item.classList.add("active");
            document.getElementById(target).classList.add("active");

            // Update header
            if (sectionTitles[target]) {
                headerTitle.textContent = sectionTitles[target].title;
                headerSubtitle.textContent = sectionTitles[target].subtitle;
            }

            // Clean URL (optional)
            if (window.history && window.history.replaceState) {
                const newUrl = window.location.pathname;
                window.history.replaceState({}, document.title, newUrl);
            }
        });
    });
});
</script>


    <!-- JavaScript -->
  <script>
document.addEventListener("DOMContentLoaded", () => {
    const navItems = document.querySelectorAll("#caregiver-page .nav li");
    const sections = document.querySelectorAll("#caregiver-page .content-section");
    const sidebar = document.querySelector("#caregiver-page .sidebar");
    const overlay = document.querySelector("#caregiver-page .overlay");
    const logoutBtn = document.getElementById("logout-btn");
    const menuBtn = document.getElementById("menu-btn");


            function loadNotifications() {
        fetch("Caregiver.php?action=fetch_notifications")
            .then(res => res.json())
            .then(data => {
            const notifList = document.getElementById("notif-list");
            notifList.innerHTML = "";

            const unreadCount = data.filter(n => n.is_read == 0).length;
            document.getElementById("notif-count").textContent = unreadCount;

            if (data.length === 0) {
                notifList.innerHTML = "<p>No notifications</p>";
                return;
            }

            data.forEach(n => {
                const card = document.createElement("div");
                card.className = "notif-card" + (n.is_read == 0 ? " unread" : "");
                card.innerHTML = `
                <label class="notif-item">
                    <input type="checkbox" class="notif-check" value="${n.id}">
                    <div class="notif-content">
                    <p><strong>From: Dr. ${n.doctor_first} ${n.doctor_last}</strong></p>
                    <p>${n.message}</p>
                    <small>${n.created_at}</small>
                    <button class="heart-btn" data-id="${n.id}">❤️</button>
                    </div>
                </label>
                `;
                notifList.appendChild(card);
            });

            // ❤️ Mark as read
            document.querySelectorAll(".heart-btn").forEach(btn => {
                btn.addEventListener("click", () => {
                const id = btn.dataset.id;
                fetch("Caregiver.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: "action=mark_read&id=" + id
                })
                    .then(res => res.json())
                    .then(r => {
                    if (r.status === "success") loadNotifications();
                    });
                });
            });
            })
            .catch(err => console.error("Notification fetch error:", err));
        }


    // 🚀 Run once on page load
    loadNotifications();
    // ⏳ Refresh every 5 sec
    setInterval(loadNotifications, 5000);
 
            // === Select All Checkbox ===
        document.addEventListener("change", e => {
        if (e.target.id === "select-all") {
            document.querySelectorAll(".notif-check").forEach(cb => {
            cb.checked = e.target.checked;
            });
        }
        });

        // === Delete Selected Notifications ===
        document.addEventListener("click", async e => {
        if (e.target.id === "delete-selected") {
            const checked = Array.from(document.querySelectorAll(".notif-check:checked"));
            if (checked.length === 0) {
            alert("Please select at least one notification to delete.");
            return;
            }

            if (!confirm(`Are you sure you want to delete ${checked.length} notification(s)?`)) return;

            const ids = checked.map(cb => cb.value);

            const res = await fetch("Caregiver.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "delete_notifications", ids })
            });

            const data = await res.json();
            if (data.status === "success") {
            alert("Selected notifications deleted.");
            loadNotifications();
            } else {
            alert("Error deleting notifications.");
            }
        }
        });


                function showPopup(message) {
            let popup = document.createElement("div");
            popup.innerText = message;
            popup.style.position = "fixed";
            popup.style.bottom = "20px";
            popup.style.right = "20px";
            popup.style.background = "#007bff";
            popup.style.color = "white";
            popup.style.padding = "15px";
            popup.style.borderRadius = "8px";
            popup.style.boxShadow = "0 4px 8px rgba(0,0,0,0.3)";
            popup.style.zIndex = "9999";
            document.body.appendChild(popup);
            setTimeout(() => popup.remove(), 6000);
            }

    // 🔓 Logout button
    if (logoutBtn) {
        logoutBtn.addEventListener("click", () => {
            if (confirm("Are you sure you want to log out?")) {
                localStorage.removeItem("activeTab");
                window.location.href = "logout.php";
            }
        });
    }

    // ✅ Ask browser for notification permission
    if (Notification.permission !== "granted") {
        Notification.requestPermission();
    }

    // ✅ Poll server every 30s for unread notifications (push style)
    setInterval(() => {
        fetch("Caregiver.php?action=fetch_notifications")
            .then(response => response.json())
            .then(data => {
                data.forEach(notif => {
                    if (notif.is_read == 0) {
                        new Notification("MARS System", {
                            body: notif.message,
                            icon: "icon.png" // optional
                        });
                    }
                });
            });
    }, 30000);
    



    
    // 📌 Navigation tabs
    navItems.forEach(item => {
        if (!item.dataset.target) return;
        item.addEventListener("click", () => {
            navItems.forEach(nav => nav.classList.remove("active"));
            item.classList.add("active");

            sections.forEach(section => section.classList.remove("active"));
            document.getElementById(item.dataset.target).classList.add("active");
        });
    });

    document.getElementById("notif-bell").addEventListener("click", () => {
    // Activate Notifications tab
    navItems.forEach(nav => nav.classList.remove("active"));
    sections.forEach(section => section.classList.remove("active"));

    document.querySelector('[data-target="notifications"]').classList.add("active");
    document.getElementById("notifications").classList.add("active");
});


    // 📱 Sidebar toggle
    menuBtn.addEventListener("click", () => {
        sidebar.classList.toggle("active");
        overlay.classList.toggle("active");
    });

    overlay.addEventListener("click", () => {
        sidebar.classList.remove("active");
        overlay.classList.remove("active");
    });
});
</script>
</body>
</html>
