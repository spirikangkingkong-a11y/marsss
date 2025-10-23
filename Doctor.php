<?php
session_start();

// 🔒 Prevent caching issues
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

$host = "localhost";
$user = "root";
$pass = "";
$db = "mars";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

$doctor_id = $_SESSION['user_id'] ?? 0;

/* === GET DOCTOR INFO === */
$sql = "SELECT * FROM users WHERE id = ? AND role = 'doctor'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "Access denied. Not a doctor account.";
    exit;
}

/* === HANDLE APPOINTMENT  ACTIONS === */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Appointment approve / reject
    if (isset($_POST['approve']) || isset($_POST['reject'])) {
        $appointment_id = intval($_POST['appointment_id']);

        // Get the appointment time
        $stmt = $conn->prepare("SELECT appointment_date FROM appointments WHERE appointment_id=? AND doctor_id=?");
        $stmt->bind_param("ii", $appointment_id, $doctor_id);
        $stmt->execute();
        $stmt->bind_result($appt_time);
        $stmt->fetch();
        $stmt->close();

        if ($appt_time) {
            $appointment_time = new DateTime($appt_time);

            // Prevent approving past appointments
          if ($appointment_time < $now) {
              echo "<script>alert('Cannot approve an appointment in the past.'); window.location.href='doctor.php';</script>";
              exit;
          }

            if (isset($_POST['approve'])) {
                $new_status = 'confirmed';

                // === Check daily appointment limit ===
                $appt_day = $appointment_time->format('Y-m-d');
                $check_stmt = $conn->prepare("
                    SELECT appointment_date
                    FROM appointments 
                    WHERE doctor_id = ? 
                      AND DATE(appointment_date) = ? 
                      AND status = 'confirmed'
                ");
                $check_stmt->bind_param("is", $doctor_id, $appt_day);
                $check_stmt->execute();
                $result_check = $check_stmt->get_result();
                $check_stmt->close();

                // === Conflict check (1-hour rule) ===
                while ($row = $result_check->fetch_assoc()) {
                    $existing_time = new DateTime($row['appointment_date']);
                    $diff_minutes = abs($appointment_time->getTimestamp() - $existing_time->getTimestamp()) / 60;

                    if ($diff_minutes < 60) {
                        echo "<script>alert('Conflict: You already have an appointment at " 
                             . $existing_time->format('h:i A') . ".'); 
                             window.location.href='doctor.php';</script>";
                        exit;
                    }
                }

                // === Optional daily limit check ===
                $limit_stmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM appointments 
                    WHERE doctor_id = ? 
                      AND DATE(appointment_date) = ? 
                      AND status = 'confirmed'
                ");
                $limit_stmt->bind_param("is", $doctor_id, $appt_day);
                $limit_stmt->execute();
                $limit_stmt->bind_result($appt_count);
                $limit_stmt->fetch();
                $limit_stmt->close();

                if ($appt_count >= 5) {
                    echo "<script>alert('You cannot handle more than 5 appointments in a single day.'); window.location.href='doctor.php';</script>";
                    exit;
                }

            } else {
                // === Reject logic (untouched) ===
                $new_status = 'cancelled';
            }

            // === Update appointment status ===
            $stmt = $conn->prepare("UPDATE appointments SET status=? WHERE appointment_id=? AND doctor_id=?");
            $stmt->bind_param("sii", $new_status, $appointment_id, $doctor_id);
            $stmt->execute();
            $stmt->close();

              // ✅ Notify caregiver For Appointment
            $cg_stmt = $conn->prepare("
                SELECT u.id, u.firstname, u.surname 
                FROM collaboration_requests cr
                JOIN users u ON u.id = cr.caregiver_id
                WHERE cr.doctor_id = ? AND cr.status = 'approved'
                LIMIT 1
            ");
            $cg_stmt->bind_param("i", $doctor_id);
            $cg_stmt->execute();
            $caregiver = $cg_stmt->get_result()->fetch_assoc();
            $cg_stmt->close();

            if ($caregiver) {
                $caregiver_id   = $caregiver['id'];
                $caregiver_name = $caregiver['firstname'] . " " . $caregiver['surname'];
                $doctor_name    = $user['firstname'] . " " . $user['surname'];
                $appt_formatted = $appointment_time->format("M d, Y h:i A");

               
                $msg = "Upcoming Appointment scheduled on {$appt_formatted}.";


            $n_stmt = $conn->prepare("
                INSERT INTO notifications 
                (doctor_id, doctor_name, caregiver_id, caregiver_name, patient_id, patient_name, message, is_read, created_at)
                SELECT ?, ?, ?, ?, a.patient_id, a.patient_name, ?, 0, NOW()
                FROM appointments a
                WHERE a.appointment_id = ?
            ");
            $n_stmt->bind_param("issssi", $doctor_id, $doctor_name, $caregiver_id, $caregiver_name, $msg, $appointment_id);

              $n_stmt->execute();
              $n_stmt->close();

            }

            if ($new_status === 'confirmed') {
                $alert_msg = 'Appointment confirmed and caregiver notified!';
            } elseif ($new_status === 'cancelled') {
                $alert_msg = 'Appointment rejected successfully!';
            } else {
                $alert_msg = "Appointment status updated!";
            }
            echo "<script>alert('Appointment {$new_status} successfully!'); window.location.href='doctor.php?tab=appointments';</script>";
                exit;

           
        }
    }
}


  

    // === Collaboration approve / reject ===
    if (isset($_POST['approve_collab']) || isset($_POST['reject_collab'])) {
        $request_id = intval($_POST['request_id']);
        $new_status = isset($_POST['approve_collab']) ? 'approved' : 'rejected';

        $stmt = $conn->prepare("UPDATE collaboration_requests SET status=? WHERE request_id=? AND doctor_id=?");
        $stmt->bind_param("sii", $new_status, $request_id, $doctor_id);
        $stmt->execute();
        $stmt->close();

        header("Location: doctor.php?tab=my-collaborations");
        exit;
    }

    // === SAVE PRESCRIPTION ===
    if (isset($_POST['save_prescription'])) {
        $caregiver_id  = intval($_POST['caregiver_id'] ?? 0);
        $patient_id    = intval($_POST['patient_id'] ?? 0);
        $medicine      = trim($_POST['medicine_name'] ?? '');
        $dosage        = trim($_POST['dosage_instructions'] ?? '');
        $start_date    = $_POST['start_date'] ?? null;
        $end_date      = $_POST['end_date'] ?? null;
        $reminder_time = $_POST['reminder_time'] ?? null;
        $frequency_type= $_POST['frequency_type'] ?? null;

          $start_formatted = date("M d, Y", strtotime($start_date));
          $end_formatted   = date("M d, Y", strtotime($end_date));


        if ($caregiver_id <= 0 || $patient_id <= 0 || $medicine === '' || $dosage === '') {
            echo "<script>alert('Please fill required fields.'); window.location.href='doctor.php?tab=prescriptions';</script>";
            exit;
        }

        $interval_hours = null;
        $times_per_day  = null;

        if ($frequency_type === 'interval') {
            $interval_hours = isset($_POST['interval_hours']) ? intval($_POST['interval_hours']) : null;
        } elseif ($frequency_type === 'times_per_day') {
            $times_per_day = isset($_POST['times_per_day']) ? intval($_POST['times_per_day']) : null;      
        }

        // === Caregiver + Patient names ===
        $cg_stmt = $conn->prepare("SELECT firstname, surname FROM users WHERE id = ?");
        $cg_stmt->bind_param("i", $caregiver_id);
        $cg_stmt->execute();
        $cg_name = $cg_stmt->get_result()->fetch_assoc();
        $cg_stmt->close();

        $pt_stmt = $conn->prepare("SELECT firstname, surname FROM users WHERE id = ?");
        $pt_stmt->bind_param("i", $patient_id);
        $pt_stmt->execute();
        $pt_name = $pt_stmt->get_result()->fetch_assoc();
        $pt_stmt->close();

        if (!$cg_name || !$pt_name) {
            echo "<script>alert('Caregiver or patient not found.'); window.location.href='doctor.php?tab=prescriptions';</script>";
            exit;
        }

        $caregiver_name = trim($cg_name['firstname'] . ' ' . $cg_name['surname']);
        $patient_name   = trim($pt_name['firstname'] . ' ' . $pt_name['surname']);
        $doctor_name    = trim(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? ''));

        // === Transaction start ===
        $conn->begin_transaction();

        // Medicine Reminders
        $m_stmt = $conn->prepare("
            INSERT INTO medicine_reminders
            (caregiver_id, caregiver_name, patient_id, patient_name, medicine_name, dosage_instructions,
             start_date, end_date, reminder_time, frequency_type, interval_hours, times_per_day, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,  'active')
        ");
        $m_stmt->bind_param(
            "isisssssssii",
            $caregiver_id, $caregiver_name,
            $patient_id, $patient_name,
            $medicine, $dosage,
            $start_date, $end_date, $reminder_time,
            $frequency_type, $interval_hours, $times_per_day
        );
        if (!$m_stmt->execute()) {
            $conn->rollback();
            echo "<script>alert('Failed to save reminder.'); window.location.href='doctor.php?tab=prescriptions';</script>";
            exit;
        }
        $reminder_id = $m_stmt->insert_id;
        $m_stmt->close();

        // Notifications
       $frequency_text = '';
            if ($frequency_type === 'interval' && $interval_hours) {
                $frequency_text = "Every {$interval_hours} hours";
            } elseif ($frequency_type === 'times_per_day' && $times_per_day) {
                $frequency_text = "{$times_per_day} times per day";
            }

        $msg = "To: {$caregiver_name} — You Have a New Medicine Prescription for Patient: {$patient_name}: {$medicine} ({$dosage}). Start Date: {$start_formatted}, End Date: {$end_formatted}" . ($frequency_text ? ", {$frequency_text}" : "");

        $n_stmt = $conn->prepare("
            INSERT INTO notifications
            (doctor_id, doctor_name, caregiver_id, caregiver_name, patient_id, patient_name, medicine_reminder_id, message, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ");
     
        $n_stmt->bind_param(
            "isisisis",
            $doctor_id, $doctor_name,
            $caregiver_id, $caregiver_name,
            $patient_id, $patient_name,
            $reminder_id, $msg
        );
        if (!$n_stmt->execute()) {
            $conn->rollback();
            echo "<script>alert('Failed to create notification.'); window.location.href='doctor.php?tab=prescriptions';</script>";
            exit;
        }
        $n_stmt->close();

        // Commit transaction
        $conn->commit();
        echo "<script>alert('Prescription saved and caregiver notified!'); window.location.href='doctor.php?tab=prescriptions';</script>";
        exit;
    }


/* === DATA FETCHING FOR DISPLAY === */

// Count caregivers
$caregiver_count_sql = "
    SELECT COUNT(DISTINCT caregiver_id) AS total_caregivers
    FROM collaboration_requests
    WHERE doctor_id = ? AND status = 'approved'
";
$caregiver_count_stmt = $conn->prepare($caregiver_count_sql);
$caregiver_count_stmt->bind_param("i", $doctor_id);
$caregiver_count_stmt->execute();
$caregiver_count_row = $caregiver_count_stmt->get_result()->fetch_assoc();
$total_caregivers = $caregiver_count_row['total_caregivers'] ?? 0;
$caregiver_count_stmt->close();

// Appointments
$appointments_sql = "
    SELECT 
        a.appointment_id, 
        a.appointment_date, 
        a.status,
        p.firstname AS patient_firstname,
        p.surname AS patient_surname,
        c.firstname AS caregiver_firstname,
        c.surname AS caregiver_surname
    FROM appointments a
    JOIN users p ON p.id = a.patient_id
    LEFT JOIN users c ON c.id = a.caregiver_id
    WHERE a.doctor_id = ?
      AND (
            a.status = 'pending' 
            OR (a.status = 'confirmed' AND a.appointment_date >= NOW())
          )
    ORDER BY a.appointment_date ASC
    LIMIT 10
";

$appointments_stmt = $conn->prepare($appointments_sql);
$appointments_stmt->bind_param("i", $doctor_id);
$appointments_stmt->execute();
$appointments = $appointments_stmt->get_result();
$appointments_stmt->close();




// === Approve Reschedule ===
if (isset($_POST['approve_reschedule'])) {
    $appointment_id = intval($_POST['appointment_id']);

    // Get doctor_id and the requested new date
    $sql = "SELECT doctor_id, reschedule_new_date FROM appointments WHERE appointment_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();

    $doctor_id = $appointment['doctor_id'];
    $new_date = $appointment['reschedule_new_date'];

    // ✅ Check if another appointment already exists within 1 hour of the new time
    $check_sql = "
        SELECT * FROM appointments
        WHERE doctor_id = ?
        AND status = 'confirmed'
        AND appointment_id != ?
        AND ABS(TIMESTAMPDIFF(MINUTE, appointment_date, ?)) < 60
    ";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("iis", $doctor_id, $appointment_id, $new_date);
    $check_stmt->execute();
    $conflict = $check_stmt->get_result();

    if ($conflict->num_rows > 0) {
        // ❌ Conflict exists – cannot approve
        echo "<script>alert('⚠ Cannot approve. There is already an appointment within 1 hour of the requested time.');window.location.href='Doctor.php?tab=reschedule';</script>";
    } else {
        // ✅ No conflict – approve
        $sql = "UPDATE appointments 
                SET appointment_date = reschedule_new_date, 
                    reschedule_status = 'approved', 
                    status = 'confirmed'
                WHERE appointment_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $appointment_id);
        $stmt->execute();

        echo "<script>alert('✅ Reschedule Approved Successfully');window.location.href='Doctor.php?tab=reschedule';</script>";
    }
}

// === Reject Reschedule ===
if (isset($_POST['reject_reschedule'])) {
    $appointment_id = intval($_POST['appointment_id']);

    $sql = "UPDATE appointments 
            SET reschedule_status = 'rejected'
            WHERE appointment_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();

    // ✅ This is the reject echo you were asking for
    echo "<script>alert('❌ Reschedule Request Rejected');window.location.href='Doctor.php?tab=reschedule';</script>";
}


// Notifications READ and UNREAD

$merged_sql = "
    SELECT id, message, caregiver_name, patient_name, is_read, created_at, 'normal' AS source
    FROM notifications
    WHERE doctor_id = ?
    
    UNION ALL

    SELECT id, message, caregiver_name, patient_name, is_read, created_at, 'reschedule' AS source
    FROM reschedule_notifications
    WHERE doctor_id = ?

    ORDER BY created_at DESC
    LIMIT 30
";
$stmt = $conn->prepare($merged_sql);
$stmt->bind_param("ii", $doctor_id, $doctor_id);
$stmt->execute();
$all_notifications = $stmt->get_result();
$stmt->close();

// Caregivers for dropdown
$caregivers_sql = "
    SELECT u.id, u.firstname, u.surname
    FROM collaboration_requests cr
    JOIN users u ON u.id = cr.caregiver_id
    WHERE cr.doctor_id = ? AND cr.status = 'approved'
";
$caregivers_stmt = $conn->prepare($caregivers_sql);
$caregivers_stmt->bind_param("i", $doctor_id);
$caregivers_stmt->execute();
$caregivers_result = $caregivers_stmt->get_result();
$caregivers_stmt->close();

// Patients by caregiver
$patients_by_caregiver = null;
$caregiver_info = null;
if (!empty($_GET['caregiver_id'])) {
    $cg_id = intval($_GET['caregiver_id']);
    $sql = "
        SELECT u.firstname, u.surname, TIMESTAMPDIFF(YEAR, u.birthdate, CURDATE()) AS age,
               ps.medical_condition, ps.status
        FROM caregiver_patients cp
        JOIN users u ON u.id = cp.patient_id
        LEFT JOIN patient_status ps ON ps.patient_id = u.id
        JOIN collaboration_requests cr 
             ON cr.caregiver_id = cp.caregiver_id 
            AND cr.doctor_id = ? 
            AND cr.status = 'approved'
        WHERE cp.caregiver_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $doctor_id, $cg_id);
    $stmt->execute();
    $patients_by_caregiver = $stmt->get_result();
    $stmt->close();

    $cg_stmt = $conn->prepare("SELECT firstname, surname FROM users WHERE id = ?");
    $cg_stmt->bind_param("i", $cg_id);
    $cg_stmt->execute();
    $caregiver_info = $cg_stmt->get_result()->fetch_assoc();
    $cg_stmt->close();
}

//  Pending Collaboration requests
$collab_sql = "
  SELECT cr.request_id, u.firstname, u.surname, cr.requested_at, cr.caregiver_id
  FROM collaboration_requests cr
  JOIN users u ON u.id = cr.caregiver_id
  WHERE cr.doctor_id = ? AND cr.status = 'pending'
";
$collab_stmt = $conn->prepare($collab_sql);
$collab_stmt->bind_param("i", $doctor_id);
$collab_stmt->execute();
$collab_requests = $collab_stmt->get_result();
$collab_stmt->close();

// Approved collaborations
$my_collab_sql = "
  SELECT cr.request_id, u.firstname, u.surname, cr.requested_at, cr.caregiver_id
  FROM collaboration_requests cr
  JOIN users u ON u.id = cr.caregiver_id
  WHERE cr.doctor_id = ? AND cr.status = 'approved'
";
$my_collab_stmt = $conn->prepare($my_collab_sql);
$my_collab_stmt->bind_param("i", $doctor_id);
$my_collab_stmt->execute();
$my_collaborations = $my_collab_stmt->get_result();
$my_collab_stmt->close();


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

    $specialization = $_POST['specialization'];

    $update = $conn->prepare("UPDATE users SET 
        firstname = ?, middle = ?, surname = ?, birthdate = ?, gender = ?, contact = ?, 
        email = ?, province = ?, city_address = ?, brgy_address = ?, home_address = ?, specialization = ?
        WHERE id = ?");
    $update->bind_param("ssssssssssssi", 
        $firstname, $middle, $surname, $birthdate, $gender, $contact, 
        $email, $province, $city_address, $brgy_address, $home_address, $specialization, $doctor_id
    );

    if ($update->execute()) {
        echo "<script> alert('Profile updated successfully!'); window.location.href = 'Doctor.php'; // or the page you want to go back to</script>";
    exit;
    } else {
         echo "<script>alert('Error updating profile!'); window.location.href = 'Doctor.php';</script>";
    exit;
    }
    $update->close();
}


// === Delete Selected Notifications ===
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    if (isset($input['action']) && $input['action'] === "delete_notifications") {
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

        if ($stmt->execute()) {
            echo json_encode(["status" => "success"]);
        } else {
            echo json_encode(["status" => "error", "message" => $stmt->error]);
        }
        $stmt->close();
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="style.css" />
  <title>Doctor Dashboard</title>
</head>

<body id="doctor-page">

  <!-- === TOP HEADER === -->
<header class="top-header">
    <div class="header-left">
      <h2 id="header-title">🩺 MARS Doctor Dashboard</h2>
      <span class="welcome-text" id="header-subtitle">
        Welcome Doctor, <?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? '')); ?>!
      </span>
    </div>
  </header>


  <div id="main-page">
    <div class="container">
      <div class="overlay"></div>
      <button class="hamburger" id="menu-btn">&#9776;</button>

      <!-- Sidebar -->
      <aside class="sidebar">
        <div class="profile-circle"></div>
        <ul class="nav">
          <li data-target="dashboard" <?php if (!isset($_GET['tab']) || $_GET['tab'] === 'dashboard') echo 'class="active"'; ?>>Dashboard</li>
          <li data-target="my-collaborations" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'my-collaborations') echo 'class="active"'; ?>>My Collaborations</li>
          <li data-target="appointments" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'appointments') echo 'class="active"'; ?>>Appointments</li>
          <li data-target="notifications" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'notifications') echo 'class="active"'; ?>>Notifications</li>
          <li data-target="prescriptions" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'prescriptions') echo 'class="active"'; ?>>Prescriptions</li>
          <li data-target="profile" <?php if (isset($_GET['tab']) && $_GET['tab'] === 'profile') echo 'class="active"'; ?>>Profile Information</li>
          <li id="logout-btn">Log out</li>
        </ul>
      </aside>

      <!-- Main Content -->
      <main class="main-content">
        <!-- Dashboard -->
       <div id="dashboard" class="content-section <?php if (!isset($_GET['tab']) || $_GET['tab']==='dashboard') echo 'active'; ?>">
          <div class="cards">
            <div class="card">
              <div class="card-icon">&#128100;</div>
              <div class="card-info">
                <h3>Total Caregivers</h3>
                <p><?php echo $total_caregivers; ?> Caregivers</p>
              </div>

            </div>
            <div class="card">
              <div class="card-icon">&#128197;</div>
              <div class="card-info">
                <h3>Upcoming Appointments</h3>
                <p><?php echo $appointments ? $appointments->num_rows : 0; ?> Scheduled</p>
              </div>
            </div>
          </div>
        </div>

        

        <!-- My Collaborations -->
        <div id="my-collaborations" class="content-section <?php if (isset($_GET['tab']) && $_GET['tab']==='my-collaborations') echo 'active'; ?>">
          <div class="left-column">
              <h1>Caregiver Requests</h1>
          <table>
            <thead>
              <tr><th>Caregiver</th><th>Requested At</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php if ($collab_requests && $collab_requests->num_rows > 0): ?>
                <?php while ($row = $collab_requests->fetch_assoc()): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($row['firstname']." ".$row['surname']); ?></td>
                    <td><?php echo htmlspecialchars($row['requested_at']); ?></td>
                    <td>
                      <form method="POST" action="">
                        <input type="hidden" name="request_id" value="<?php echo $row['request_id']; ?>">
                        <button type="submit" name="approve_collab">Approve</button>
                        <button type="submit" name="reject_collab">Reject</button>
                      </form>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="3">No Caregiver requests.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>

          <h1>My Caregiver's list</h1>
          <table>
            <thead>
              <tr><th>Caregiver</th><th>Since</th></tr>
            </thead> 
           <tbody>
                <?php if ($my_collaborations && $my_collaborations->num_rows > 0): ?>
                    <?php while ($row = $my_collaborations->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['firstname']." ".$row['surname']); ?></td>
                            <td><?php echo htmlspecialchars(date("M d, Y", strtotime($row['requested_at']))); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="2">No active collaborations yet.</td></tr>
                <?php endif; ?>
                </tbody>
          </table>
        </div>
          
          <!-- My Patients under Collaborations -->
           <div class="right-column">
          <form method="GET" action="">
            <label for="caregiver">Select Caregiver:</label>
            <select name="caregiver_id" id="caregiver" onchange="window.location.href='doctor.php?tab=my-collaborations&caregiver_id=' + this.value;">
              <option value="">-- Choose Caregiver --</option>
              <?php 
              if ($caregivers_result) $caregivers_result->data_seek(0);
              while ($cg = $caregivers_result->fetch_assoc()): ?>
                <option value="<?php echo $cg['id']; ?>" <?php if (isset($_GET['caregiver_id']) && $_GET['caregiver_id'] == $cg['id']) echo 'selected'; ?> >
                  <?php echo htmlspecialchars($cg['firstname'] . " " . $cg['surname']); ?>
                </option>
              <?php endwhile; ?>
            </select>
          </form>

          <?php if (!empty($_GET['caregiver_id'])): ?>
            <?php if ($caregiver_info): ?>
              <h3>Patients Handled by Caregiver: <span style="color:#dc3545;"><?php echo htmlspecialchars($caregiver_info['firstname'] . " " . $caregiver_info['surname']); ?></span></h3>
            <?php else: ?>
              <h3 style="color:#dc3545;">Caregiver not found.</h3>
            <?php endif; ?>

            <table>
              <thead>
                <tr><th>Patient Name</th><th>Age</th><th>Medical Condition</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php if ($patients_by_caregiver && $patients_by_caregiver->num_rows > 0): ?>
                  <?php while ($row = $patients_by_caregiver->fetch_assoc()): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($row['firstname']." ".$row['surname']); ?></td>
                      <td><?php echo htmlspecialchars($row['age']); ?></td>
                      <td><?php echo htmlspecialchars($row['medical_condition'] ?? 'N/A'); ?></td>
                      <td><?php echo htmlspecialchars($row['status'] ?? 'N/A'); ?></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="4">No patients assigned to this caregiver.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          <?php endif; ?>
                </div>
        </div>


         <?php
             $reschedule_query = "
                        SELECT 
                            a.appointment_id, 
                            a.patient_name, 
                            a.appointment_date, 
                            a.reschedule_new_date, 
                            a.reschedule_status,
                            c.firstname AS caregiver_firstname,
                            c.surname AS caregiver_surname
                        FROM appointments a
                        LEFT JOIN users c ON a.caregiver_id = c.id
                        WHERE a.doctor_id = ?
                        AND a.reschedule_status = 'pending'
                    ";
                    $stmt = $conn->prepare($reschedule_query);
                    $stmt->bind_param("i", $doctor_id);
                    $stmt->execute();
                    $reschedule_requests = $stmt->get_result();

           ?>

      
       <!-- Appointments -->
<div id="appointments" class="content-section <?php if (isset($_GET['tab']) && $_GET['tab']==='appointments') echo 'active'; ?>">
    <h1>📅Appointments</h1>
    <table>
        <thead>
            <tr>
                <th>Patient</th>
                <th>Caregiver</th>
                <th>Date</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($appointments && $appointments->num_rows > 0): ?>
                <?php while ($row = $appointments->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['patient_firstname'] . " " . $row['patient_surname']); ?></td>
                        <td>
                            <?php 
                                if (!empty($row['caregiver_firstname'])) {
                                    echo htmlspecialchars($row['caregiver_firstname'] . " " . $row['caregiver_surname']);
                                } else {
                                    echo "<em>None</em>";
                                }
                            ?>
                        </td>
                        <td><?php echo date("M d, Y h:i A", strtotime($row['appointment_date'])); ?></td>
                        <td>
                            <div class="status-actions">
                                <span class="status <?php echo strtolower($row['status']); ?>"><?php echo ucfirst($row['status']); ?></span>
                                <?php if ($row['status'] === 'pending'): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="appointment_id" value="<?php echo $row['appointment_id']; ?>">
                                        <button type="submit" name="approve">✅ Approve</button>
                                        <button type="submit" name="reject">❌ Reject</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4">No appointments found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>



          
            <h2>📅 Reschedule Requests</h2>
                <table>
                    <tr>
                        <th>Patient</th>
                        <th>Caregiver</th>
                        <th>Current Date</th>
                        <th>Requested New Date</th>
                        <th>Action</th>
                    </tr>

                    <?php if ($reschedule_requests->num_rows > 0): ?>
                        <?php while ($row = $reschedule_requests->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['patient_name']); ?></td>
                                <td><?= htmlspecialchars($row['caregiver_firstname'] . " " . $row['caregiver_surname']); ?></td>
                                <td><?= date("M d, Y h:i A", strtotime($row['appointment_date'])); ?></td>
                                <td><?= date("M d, Y h:i A", strtotime($row['reschedule_new_date'])); ?></td>
                                <td>
                                    <form method="POST" action="">
                                        <input type="hidden" name="appointment_id" value="<?= $row['appointment_id']; ?>">
                                        <button type="submit" name="approve_reschedule" class="approve-btn">✅ Approve</button>
                                        <button type="submit" name="reject_reschedule" class="reject-btn">❌ Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5">No reschedule requests at the moment.</td></tr>
                    <?php endif; ?>
                </table>


        </div>
          <div id="notifications" class="content-section <?php if (isset($_GET['tab']) && $_GET['tab']==='notifications') echo 'active'; ?>">
              <h1>Notifications</h1>

              <div class="notif-actions">
                  <label><input type="checkbox" id="select-all"> Select All</label>
                  <button id="delete-selected">🗑 Delete Selected</button>
              </div>

              <?php if ($all_notifications && $all_notifications->num_rows > 0): ?>
                  <?php while ($n = $all_notifications->fetch_assoc()): ?>
                      <div class="notif-card <?php echo $n['source'] !== 'reschedule' && $n['is_read'] ? 'read' : 'unread'; ?>">
                          <input type="checkbox" class="notif-checkbox" value="<?php echo $n['id']; ?>">

                          <div class="notif-content">
                              <p><?php echo htmlspecialchars($n['message']); ?></p>
                              <small><?php echo date("M d, Y h:i A", strtotime($n['created_at'])); ?></small>

                              <?php if (!empty($n['caregiver_name'])): ?>
                                  <small>
                                      <strong>From:</strong> <?php echo $n['caregiver_name']; ?> |
                                      <strong>Patient:</strong> <?php echo $n['patient_name']; ?>
                                  </small>
                              <?php endif; ?>

                              <?php if ($n['source'] === 'reschedule'): ?>
                                  <span style="color: #ff9800; font-size: 12px;">⟳ Reschedule Request</span>
                              <?php endif; ?>
                          </div>

                          <!-- ✅ Hide Read/Unread for reschedule notifications -->
                          <?php if ($n['source'] !== 'reschedule'): ?>
                              <span class="notif-status <?php echo $n['is_read'] ? 'status-read' : 'status-unread'; ?>">
                                  <?php echo $n['is_read'] ? '✔ Read' : '🔔 Unread'; ?>
                              </span>
                          <?php endif; ?>

                      </div>
                  <?php endwhile; ?>
              <?php else: ?>
                  <p>No notifications yet.</p>
              <?php endif; ?>
          </div>


            


                  <!-- Prescriptions -->
          <div id="prescriptions" class="content-section <?php if (isset($_GET['tab']) && $_GET['tab']==='prescriptions') echo 'active'; ?>">
           <form class="prescription-form" method="POST" action="doctor.php?tab=prescriptions">
                <label for="caregiver_id">Select Caregiver:</label>
              <select name="caregiver_id" id="prescription_caregiver" 
                  onchange="window.location.href='doctor.php?tab=prescriptions&caregiver_id=' + this.value;">
                     <option value="">-- Choose Caregiver --</option>
                    <?php  
                    if ($caregivers_result) $caregivers_result->data_seek(0);
                    while ($cg = $caregivers_result->fetch_assoc()): ?>
                      <option value="<?php echo $cg['id']; ?>" 
                        <?php if (isset($_GET['caregiver_id']) && $_GET['caregiver_id'] == $cg['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($cg['firstname']." ".$cg['surname']); ?>
                      </option>
                    <?php endwhile; ?>
                </select>

                <label for="patient_id">Select Patient:</label>
                <select name="patient_id" id="patient_id" required>
                    <option value="">-- Choose Patient --</option>
                    <?php                    
                      if (!empty($_GET['caregiver_id'])) {
                          $cid = intval($_GET['caregiver_id']);
                          $sql = "
                              SELECT u.id, u.firstname, u.surname
                              FROM caregiver_patients cp
                              JOIN users u ON u.id = cp.patient_id
                              JOIN collaboration_requests cr 
                                  ON cr.caregiver_id = cp.caregiver_id 
                                  AND cr.doctor_id = {$doctor_id}
                                  AND cr.status = 'approved'
                              WHERE cp.caregiver_id = {$cid}
                          ";
                          $patients_result = $conn->query($sql);
                          while ($pt = $patients_result->fetch_assoc()) {
                              echo "<option value='{$pt['id']}'>".htmlspecialchars($pt['firstname']." ".$pt['surname'])."</option>";
                          }
                      }
                      ?>
                </select>
                      
                <label for="medicine_name">Medicine Name:</label>
                <input type="text" name="medicine_name" id="medicine_name" required>

                <label for="dosage">Dosage Instructions:</label>
                <input type="text" name="dosage_instructions" id="dosage" required>

                <label for="start_date">Start Date:</label>
                <input type="date" name="start_date" id="start_date" required>

                <label for="end_date">End Date:</label>
                <input type="date" name="end_date" id="end_date" required>

                   <label>How should the medicine be taken?</label>

                  <select name="frequency_type" id="frequency_type" onchange="showFrequencyFields()">
                    <option value="interval" selected>Every X hours</option>                   
                    <option value="times_per_day">X times per day</option>                  
                  </select>

                  <div id="interval_field" style="display:none;">
                    <label for="interval_hours">Every Hours:</label>
                    <input type="number" name="interval_hours" min="1" max="24"> 
                  </div>

                  <div id="times_per_day_field" style="display:none;">
                    <label for="times_per_day">Times per day:</label>
                    <input type="number" name="times_per_day" min="1" max="24"> 
                  </div>
                
                
                <button type="submit" name="save_prescription">Save Prescription</button>
              </form>

          </div>


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

      <div class="form-group">
              <label for="specialization">Doctor Specialization</label>
        <select id="specialization" name="specialization">
            <option value="">-- Select Specialization --</option>
            <option value="General Medicine" <?php if($user['specialization'] == 'General Medicine') echo 'selected'; ?>>General Medicine</option>
            <option value="Geriatrician" <?php if($user['specialization'] == 'Geriatrician') echo 'selected'; ?>>Geriatrician (Elderly Care Specialist)</option>
            <option value="Cardiologist" <?php if($user['specialization'] == 'Cardiologist') echo 'selected'; ?>>Cardiologist (Heart Specialist)</option>
            <option value="Neurologist" <?php if($user['specialization'] == 'Neurologist') echo 'selected'; ?>>Neurologist (Brain & Nerve Specialist)</option>
            <option value="Endocrinologist" <?php if($user['specialization'] == 'Endocrinologist') echo 'selected'; ?>>Endocrinologist (Diabetes & Hormones)</option>
            <option value="Pulmonologist" <?php if($user['specialization'] == 'Pulmonologist') echo 'selected'; ?>>Pulmonologist (Lungs & Breathing)</option>
            <option value="Orthopedic" <?php if($user['specialization'] == 'Orthopedic') echo 'selected'; ?>>Orthopedic (Bone & Joint Specialist)</option>
            <option value="Psychiatrist" <?php if($user['specialization'] == 'Psychiatrist') echo 'selected'; ?>>Psychiatrist (Mental Health)</option>
        </select>
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
document.addEventListener("DOMContentLoaded", () => {

  const editBtn = document.getElementById("editBtn");
  const cancelBtn = document.getElementById("cancelBtn");
  const saveBtn = document.getElementById("saveBtn");
  const profileForm = document.getElementById("profileForm");
  const contactInput = document.querySelector('input[name="contact"]');


  // Set all inputs to readonly (looks normal but uneditable)
  profileForm.querySelectorAll("input").forEach(input => {
      input.setAttribute("readonly", true);
  });

   // Set select to non-interactive but not disabled (keeps color normal)
  profileForm.querySelector("select").classList.add("readonly");

  // Enable Edit button, disable Cancel & Save
  editBtn.disabled = false;
  cancelBtn.disabled = true;
  saveBtn.style.display = "none";


  // Only allow numbers and max 11 digits in contact
  contactInput.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0,11);
  });

  // Edit button click
  editBtn.addEventListener("click", () => {
    profileForm.querySelectorAll("input").forEach(field => {
        field.removeAttribute("readonly");
        field.disabled = false;
    });

    profileForm.querySelector("select").classList.remove("readonly");

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

<script>
function loadRescheduleNotifications() {
    fetch("fetch_reschedule_notifications.php")
        .then(res => res.json())
        .then(data => {
            const list = document.getElementById("resched-notif-list");
            list.innerHTML = "";

            if (data.length === 0) {
                list.innerHTML = "<p>No reschedule notifications</p>";
                return;
            }

            data.forEach(n => {
                let card = document.createElement("div");
                card.className = "notif-card";
                card.classList.add(n.is_read == 1 ? "read" : "unread");

                card.innerHTML = `
                    <input type="checkbox" class="notif-checkbox" value="${n.id}">
                    <div class="notif-content">
                        <p>${n.message}</p>
                        <small>${n.created_at}</small>
                        <small><strong>From:</strong> ${n.caregiver_name} — <strong>Patient:</strong> ${n.patient_name}</small>
                    </div>
                    <span class="notif-status">
                        ${n.is_read == 1 ? "✔ Read" : "🔔 Unread"}
                    </span>
                `;

                list.appendChild(card);
            });
        })
        .catch(err => console.error("Reschedule notification error:", err));
}

// ✅ Run this together with normal notifications
document.addEventListener("DOMContentLoaded", () => {
    loadDoctorNotifications();      // your existing function
    loadRescheduleNotifications();  // reschedule notifications
});
</script>


  <script>
  // === Frequency Fields ===
  function showFrequencyFields() {
    document.getElementById("interval_field").style.display = "none";
    document.getElementById("times_per_day_field").style.display = "none";
    
    

    let type = document.getElementById("frequency_type").value;
    if (type === "interval") document.getElementById("interval_field").style.display = "block";
    if (type === "times_per_day") document.getElementById("times_per_day_field").style.display = "block";
   
  }

  // === Dynamic Header Text Update (Doctor Dashboard) ===
  const headerTitle = document.getElementById("header-title");
  const headerSubtitle = document.getElementById("header-subtitle");

  const sectionTitles = {
    dashboard: {
      title: "🩺 MARS Doctor Dashboard",
      subtitle: "Welcome Doctor, <?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? '')); ?>!"
    },
    "my-collaborations": {
      title: "🤝 My Collaborations",
      subtitle: "Manage caregiver collaboration requests and approved partners."
    },
    appointments: {
      title: "📅 Appointments",
      subtitle: "View, approve, or reject upcoming patient appointments."
    },
    notifications: {
      title: "🔔 Notifications Center",
      subtitle: "Check alerts, appointment updates, and caregiver messages."
    },
    prescriptions: {
      title: "💊 Prescriptions",
      subtitle: "Create and send medicine prescriptions to caregivers."
    },
    profile: {
      title: "👤 Profile Information",
      subtitle: "View and update your personal and professional details."
    }
  };

  // 🟢 Detect current section (from PHP)
  const currentSection = "<?= $_GET['tab'] ?? 'dashboard' ?>";

  // Apply the correct title/subtitle on page load
  if (sectionTitles[currentSection]) {
    headerTitle.textContent = sectionTitles[currentSection].title;
    headerSubtitle.textContent = sectionTitles[currentSection].subtitle;
  }

  // 🟡 Update dynamically when sidebar items are clicked (no reload)
  document.querySelectorAll(".sidebar .nav li[data-target]").forEach(item => {
    item.addEventListener("click", () => {
      const section = item.getAttribute("data-target");
      if (sectionTitles[section]) {
        headerTitle.textContent = sectionTitles[section].title;
        headerSubtitle.textContent = sectionTitles[section].subtitle;
      }
    });
  });


  // === Page Scripts ===
  document.addEventListener("DOMContentLoaded", () => {
    const navItems = document.querySelectorAll("#doctor-page .nav li");
    const sections = document.querySelectorAll("#doctor-page .content-section");
    const logoutBtn = document.getElementById("logout-btn");
    const menuBtn = document.getElementById("menu-btn");
    const sidebar = document.querySelector("#doctor-page .sidebar");
    const overlay = document.querySelector("#doctor-page .overlay");
    const freqSelect = document.getElementById("frequency_type");

    // Simple tab behavior
    navItems.forEach(item => {
      item.addEventListener("click", () => {
        if (!item.dataset.target) return;
        navItems.forEach(nav => nav.classList.remove("active"));
        item.classList.add("active");
        sections.forEach(section => section.classList.remove("active"));
        const el = document.getElementById(item.dataset.target);
        if (el) el.classList.add("active");
      });
    });

    // Frequency fields behavior
    if (freqSelect) {
      showFrequencyFields(); // show correct one on page load
      freqSelect.addEventListener("change", showFrequencyFields);
    }

        function loadDoctorNotifications() {
            fetch("fetch_notifications.php")
                .then(res => res.json())
                .then(data => {
                    const notifList = document.getElementById("doctor-notif-list");
                    notifList.innerHTML = "";

                    if (data.length === 0) {
                        notifList.innerHTML = "<p>No notifications</p>";
                        return;
                    }

                    data.forEach(n => {
                        let card = document.createElement("div");
                        card.className = "notif-card";
                        card.classList.add(n.is_read == 1 ? "read" : "unread");

                        card.innerHTML = `
                            <p>${n.message}</p>
                            <small>${n.created_at}</small>
                            <div class="notif-meta">
                                Status: <strong>${n.is_read == 1 ? "Read" : "Unread"}</strong>
                            </div>
                        `;

                        notifList.appendChild(card);
                    });
                })
                .catch(err => console.error("Doctor notifications error:", err));
        }

        // 🚀 Run once on load
        loadDoctorNotifications();

    // Logout button
    if (logoutBtn) {
      logoutBtn.addEventListener("click", () => {
        if (confirm("Are you sure you want to log out?")) {
          window.location.href = "logout.php";
        }
      });
    }

    // Mobile menu toggle
    if (menuBtn) {
      menuBtn.addEventListener("click", () => {
        sidebar.classList.toggle("active");
        overlay.classList.toggle("active");
      });
    }

    if (overlay) {
      overlay.addEventListener("click", () => {
        sidebar.classList.remove("active");
        overlay.classList.remove("active");
      });
    }
  });
</script>

<script>
document.getElementById('select-all').addEventListener('change', function() {
  document.querySelectorAll('.notif-checkbox').forEach(cb => cb.checked = this.checked);
});

document.getElementById('delete-selected').addEventListener('click', function() {
  const ids = Array.from(document.querySelectorAll('.notif-checkbox:checked'))
                   .map(cb => parseInt(cb.value));

  if (ids.length === 0) {
    alert("Please select at least one notification to delete.");
    return;
  }

  if (!confirm("Are you sure you want to delete selected notifications?")) return;

  fetch('doctor.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete_notifications', ids })
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === 'success') {
      alert("Selected notifications deleted successfully!");
      window.location.reload();
    } else {
      alert("Error: " + (data.message || "Failed to delete notifications."));
    }
  })
  .catch(err => alert("Request failed: " + err));
});
</script>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const navItems = document.querySelectorAll(".sidebar .nav li");
  
  navItems.forEach(li => {
    li.addEventListener("click", () => {
      const tab = li.getAttribute("data-target");

      // 👇 Update URL with tab parameter (without reloading)
      const url = new URL(window.location);
      url.searchParams.set("tab", tab);
      window.history.replaceState({}, "", url);

      // 👇 Apply active state immediately
      document.querySelectorAll(".sidebar .nav li").forEach(n => n.classList.remove("active"));
      li.classList.add("active");

      // 👇 Show matching content section
      document.querySelectorAll(".content-section").forEach(sec => sec.classList.remove("active"));
      const section = document.getElementById(tab);
      if (section) section.classList.add("active");
    });
  });

  // ✅ On page load (including hard refresh), focus correct button
  const params = new URLSearchParams(window.location.search);
  const currentTab = params.get("tab") || "dashboard";
  const activeLi = document.querySelector(`.sidebar .nav li[data-target="${currentTab}"]`);
  if (activeLi) activeLi.classList.add("active");
  
  // ✅ Show correct content section
  const activeSection = document.getElementById(currentTab);
  if (activeSection) activeSection.classList.add("active");
});
</script>

</body>
</html>
